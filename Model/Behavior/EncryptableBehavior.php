<?php
/**
 * Encryptable Behavior
 */
App::uses('Lapis', 'Lapis.Lib');
class EncryptableBehavior extends ModelBehavior {

	protected $_defaults = array(
		'column' => 'encrypted',
		'cipher' => 'aes-256-ctr',
		'document_id_digest' => 'sha256',
		'salt' => null, // generated on constructor if it is set to null (recommended)
	);
	protected $_types = array('inherit', 'string', 'number', 'boolean');

	public function setup(Model $Model, $settings = array()) {
		$this->schema[$Model->alias] = $this->_normalizeSchema($Model->documentSchema);
		$this->settings[$Model->alias] = array_merge($this->_defaults, $settings);

		// Generate behavior security salt
		if (empty($this->settings[$Model->alias]['salt'])) {
			$this->settings[$Model->alias]['salt'] = sha1(Configure::read('Security.salt') . $Model->alias);
		}
	}

	public function beforeSave(Model $Model, $options = array()) {
		$vaults = $this->_getVaultPublicKeys($Model->saveFor);

		if (empty($vaults)) {
			return false;
		}

		$document = array();
		foreach ($Model->data[$Model->alias] as $field => $value) {
			if (isset($this->schema[$Model->alias][$field])) {
				$document[$field] = $this->_handleType($value, $this->schema[$Model->alias][$field]);
				unset($Model->data[$Model->alias][$field]);
			}
		}

		$encRes = Lapis::docEncryptForMany($document, $vaults);
		$dockeys = $encRes['keys'];

		$encDoc = $encRes;
		unset($encDoc['keys']);
		$encDocJSON = json_encode($encDoc);

		// Hold the keys for afterSave() – after model ID is obtained
		$this->dockeys[$Model->alias][sha1($encDocJSON)] = $dockeys;

		$Model->data[$Model->alias][$this->settings[$Model->alias]['column']] = $encDocJSON;
		return true;
	}

	public function afterSave(Model $Model, $created, $options = array()) {
		if (isset($Model->data[$Model->alias][$this->settings[$Model->alias]['column']])) {
			$encDocJSONHash = sha1($Model->data[$Model->alias][$this->settings[$Model->alias]['column']]);
			if (!isset($this->dockeys[$Model->alias][$encDocJSONHash])) {
				throw new CakeException('Document keys not found after successful save');
			}

			$DocumentModel = ClassRegistry::init('Lapis.Document');
			foreach ($this->dockeys[$Model->alias][$encDocJSONHash] as $keyID => $docKey) {
				$docData[] = array(
					'model_id' => $this->_getModelID($Model->alias, $Model->data[$Model->alias]['id']),
					'vault_id' => $keyID,
					'key' => $docKey
				);
			}

			// Junk the keys after use
			unset($this->dockeys[$Model->alias][$encDocJSONHash]);

			return $DocumentModel->saveMany($docData);
		}
	}

	/**
	 * Extract secured document columns into conventional document columns
	 */
	public function afterFind(Model $Model, $results, $primary = false) {
		$docColumn = $this->settings[$Model->alias]['column'];
		foreach ($results as $key => $row) {
			if (array_key_exists($docColumn, $row[$Model->alias])) {
				$docFields = false;
				if (!empty($Model->requestAs)) {
					$docRow = ClassRegistry::init('Lapis.Document')->find('first', array(
						'conditions' => array(
							'model_id' => $this->_getModelID($Model->alias, $results[$key][$Model->alias]['id'], $Model->requestAs['id'])
						),
						'fields' => array('id', 'model_id', 'vault_id', 'key')
					));

					$accessor = ClassRegistry::init('Lapis.Accessor')->find('first', array(
						'conditions' => array(
							'vault_id' => $docRow['Document']['vault_id'],
							'requester_id' => $Model->requestAs['id']
						),
					));

					$vault = ClassRegistry::init('Lapis.Requester')->find('first', array(
						'conditions' => array(
							'id' => $docRow['Document']['vault_id'],
						),
						'fields' => array('id', 'vault_private_key'),
					));

					$encDoc = $results[$key][$Model->alias][$docColumn];
					$vaultKeyDoc = $vault['Requester']['vault_private_key'];
					$documentKey = $docRow['Document']['key'];
					$requesterAccessorKey = $accessor['Accessor']['key'];
					$requesterPrivateKey = ClassRegistry::init('Lapis.Requester')->getPrivateKey($Model->requestAs);

					$docFields = $this->_decryptDocument(
						$encDoc,
						$vaultKeyDoc,
						$documentKey,
						$requesterAccessorKey,
						$requesterPrivateKey
					);
				}
				if (is_array($docFields)) {
					$results[$key][$Model->alias] = array_merge($results[$key][$Model->alias], $docFields);
					unset($results[$key][$Model->alias][$docColumn]);
				} else {
					$results[$key][$Model->alias][$docColumn] = '(encrypted)';
				}
			}
		}
		return $results;
	}

	protected function _handleType($value, $type = 'string') {
		switch ($type) {
			case 'boolean':
				return (bool)$value;
				break;
			case 'number':
				return $value + 0; // reliably casting to either int or float
				break;
			case 'string':
				return (string)$value;
				break;
			case 'inherit':
			default:
				return $value;
		}
	}

	/**
	 * $documentSchema normalization
	 * Supports either named-array or non-named array
	 *
	 * Example:
	 * array('field1', 'field2' ...); or
	 * array('field1' => 'number', 'field2' => 'boolean' ...);
	 */
	protected function _normalizeSchema($documentSchema) {
		// Converting of non-named array, taking all fields as strings
		if (isset($documentSchema[0])) {
			$fields = $documentSchema;
		} else {
			$fields = array_keys($documentSchema);
		}

		// Normalized schema
		$schema = array();
		foreach ($fields as $field) {
			if (
				isset($documentSchema[$field]) &&
				in_array($documentSchema[$field], $this->_types)
			) {
				$schema[$field] = $documentSchema[$field];
			} else {
				$schema[$field] = 'inherit'; // non-enforcing, not type-casted
			}
		}
		return $schema;
	}

	/**
	 * Returns list of vault public keys
	 *
	 * @param array/string $saveFor ID or IDs of Requester
	 * @return array List of ID and associated vault public keys
	 *               or false, if any of the IDs do not have a vault
	 */
	protected function _getVaultPublicKeys($saveFor) {
		if (!is_array($saveFor)) {
			$saveFor = array($saveFor);
		}

		$Requester = ClassRegistry::init('Lapis.Requester');
		$results = $Requester->find('list', array(
			'conditions' => array('id' => $saveFor, 'vault_public_key IS NOT NULL'),
			'fields' => array('id', 'vault_public_key'),
		));

		// If any of the ID does not have a vault, return false for all
		if (count($results) !== count($saveFor)) {
			return false;
		}
		return $results;
	}

	/**
	 * Returns list of public keys to encrypt with
	 * DEPRECATED
	 */
	protected function _getPublicKeys($forKeys) {
		if (!empty($forKeys) && !is_array($forKeys)) {
			$forKeys = array($forKeys);
		}

		$KeyModel = ClassRegistry::init('Lapis.Key');
		$keyIDs = $KeyModel->getAncestorIDs($forKeys);

		$cond = array();
		if (!empty($keyIDs)) {
			$cond['Key.id'] = $keyIDs;
		} else {
			$cond['Key.parent_id'] = null; // get all root keys
		}

		$keys = $KeyModel->find('list', array(
			'conditions' => $cond,
			'fields' => array('Key.id', 'Key.public_key')
		));

		return $keys;
	}

	protected function _getModelID($modelAlias, $id) {
		return sha1($this->settings[$modelAlias]['salt'] . $id);
 	}

 	/**
 	 * Decrypt a document given the following
 	 * @param  string $encDoc Encrypted document, obtainable from target model's encrypted field
 	 * @param  string $vaultKeyDoc Encrypted vault's private key document, obtainable from Lapis.Requester's vault_private_key field
 	 * @param  string $documentKey Encrypted document key, obtainable from Lapis.Document's key field
 	 * @param  string $requesterAccessorKey Encrypted requester accessor key, obtainable from Lapis.Accessor's key field
 	 * @param  string $requesterPrivateKey Unencrypted clear requester's identity private key
 	 * @return array Successfully decrypted document, or false
 	 */
 	protected function _decryptDocument($encDoc, $vaultKeyDoc, $documentKey, $requesterAccessorKey, $requesterPrivateKey) {
		$vaultPrivate = Lapis::docDecrypt($vaultKeyDoc, $requesterAccessorKey, $requesterPrivateKey);
		if (empty($vaultPrivate)) {
			return false;
		}

		$document = Lapis::docDecrypt($encDoc, $documentKey, $vaultPrivate);
		return $document;
 	}
}
