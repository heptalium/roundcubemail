<?php

/**
 +-------------------------------------------------------------------------+
 | GnuPG (PGP) driver for the Enigma Plugin                                |
 |                                                                         |
 | Copyright (C) 2010-2015 The Roundcube Dev Team                          |
 |                                                                         |
 | Licensed under the GNU General Public License version 3 or              |
 | any later version with exceptions for skins & plugins.                  |
 | See the README file for a full license statement.                       |
 |                                                                         |
 +-------------------------------------------------------------------------+
 | Author: Aleksander Machniak <alec@alec.pl>                              |
 +-------------------------------------------------------------------------+
*/

require_once 'Crypt/GPG.php';

class enigma_driver_gnupg extends enigma_driver
{
    protected $rc;
    protected $gpg;
    protected $homedir;
    protected $user;


    function __construct($user)
    {
        $this->rc   = rcmail::get_instance();
        $this->user = $user;
    }

    /**
     * Driver initialization and environment checking.
     * Should only return critical errors.
     *
     * @return mixed NULL on success, enigma_error on failure
     */
    function init()
    {
        $homedir = $this->rc->config->get('enigma_pgp_homedir', INSTALL_PATH . 'plugins/enigma/home');
        $debug   = $this->rc->config->get('enigma_debug');

        if (!$homedir)
            return new enigma_error(enigma_error::INTERNAL,
                "Option 'enigma_pgp_homedir' not specified");

        // check if homedir exists (create it if not) and is readable
        if (!file_exists($homedir))
            return new enigma_error(enigma_error::INTERNAL,
                "Keys directory doesn't exists: $homedir");
        if (!is_writable($homedir))
            return new enigma_error(enigma_error::INTERNAL,
                "Keys directory isn't writeable: $homedir");

        $homedir = $homedir . '/' . $this->user;

        // check if user's homedir exists (create it if not) and is readable
        if (!file_exists($homedir))
            mkdir($homedir, 0700);

        if (!file_exists($homedir))
            return new enigma_error(enigma_error::INTERNAL,
                "Unable to create keys directory: $homedir");
        if (!is_writable($homedir))
            return new enigma_error(enigma_error::INTERNAL,
                "Unable to write to keys directory: $homedir");

        $this->homedir = $homedir;

        // Create Crypt_GPG object
        try {
            $this->gpg = new Crypt_GPG(array(
                'homedir'   => $this->homedir,
                // 'binary'    => '/usr/bin/gpg2',
                'debug'     => $debug ? array($this, 'debug') : false,
          ));
        }
        catch (Exception $e) {
            return $this->get_error_from_exception($e);
        }
    }

    /**
     * Encryption.
     *
     * @param string Message body
     * @param array  List of key-password mapping
     *
     * @return mixed Encrypted message or enigma_error on failure
     */
    function encrypt($text, $keys)
    {
        try {
            foreach ($keys as $key) {
                $this->gpg->addEncryptKey($key);
            }

            return $this->gpg->encrypt($text, true);
        }
        catch (Exception $e) {
            return $this->get_error_from_exception($e);
        }
    }

    /**
     * Decrypt a message
     *
     * @param string Encrypted message
     * @param array  List of key-password mapping
     *
     * @return mixed Decrypted message or enigma_error on failure
     */
    function decrypt($text, $keys = array())
    {
        try {
            foreach ($keys as $key => $password) {
                $this->gpg->addDecryptKey($key, $password);
            }

            return $this->gpg->decrypt($text);
        }
        catch (Exception $e) {
            return $this->get_error_from_exception($e);
        }
    }

    /**
     * Signing.
     *
     * @param string Message body
     * @param string Key ID
     * @param string Key password
     * @param int    Signing mode (enigma_engine::SIGN_*)
     *
     * @return mixed True on success or enigma_error on failure
     */
    function sign($text, $key, $passwd, $mode = null)
    {
        try {
            $this->gpg->addSignKey($key, $passwd);
            return $this->gpg->sign($text, $mode, CRYPT_GPG::ARMOR_ASCII, true);
        }
        catch (Exception $e) {
            return $this->get_error_from_exception($e);
        }
    }

    /**
     * Signature verification.
     *
     * @param string Message body
     * @param string Signature, if message is of type PGP/MIME and body doesn't contain it
     *
     * @return mixed Signature information (enigma_signature) or enigma_error
     */
    function verify($text, $signature)
    {
        try {
            $verified = $this->gpg->verify($text, $signature);
            return $this->parse_signature($verified[0]);
        }
        catch (Exception $e) {
            return $this->get_error_from_exception($e);
        }
    }

    /**
     * Key file import.
     *
     * @param string  File name or file content
     * @param bollean True if first argument is a filename
     *
     * @return mixed Import status array or enigma_error
     */
    public function import($content, $isfile=false)
    {
        try {
            if ($isfile)
                return $this->gpg->importKeyFile($content);
            else
                return $this->gpg->importKey($content);
        }
        catch (Exception $e) {
            return $this->get_error_from_exception($e);
        }
    }

    /**
     * Key export.
     *
     * @param string Key ID
     *
     * @return mixed Key content or enigma_error
     */
    public function export($keyid)
    {
        try {
            return $this->gpg->exportPublicKey($keyid, true);
        }
        catch (Exception $e) {
            return $this->get_error_from_exception($e);
        }
    }

    /**
     * Keys listing.
     *
     * @param string Optional pattern for key ID, user ID or fingerprint
     *
     * @return mixed Array of enigma_key objects or enigma_error
     */
    public function list_keys($pattern='')
    {
        try {
            $keys = $this->gpg->getKeys($pattern);
            $result = array();

            foreach ($keys as $idx => $key) {
                $result[] = $this->parse_key($key);
                unset($keys[$idx]);
            }

            return $result;
        }
        catch (Exception $e) {
            return $this->get_error_from_exception($e);
        }
    }

    /**
     * Single key information.
     *
     * @param string Key ID, user ID or fingerprint
     *
     * @return mixed Key (enigma_key) object or enigma_error
     */
    public function get_key($keyid)
    {
        $list = $this->list_keys($keyid);

        if (is_array($list)) {
            return $list[key($list)];
        }

        // error
        return $list;
    }

    /**
     * Key pair generation.
     *
     * @param array Key/User data (user, email, password, size)
     *
     * @return mixed Key (enigma_key) object or enigma_error
     */
    public function gen_key($data)
    {
        try {
            $debug  = $this->rc->config->get('enigma_debug');
            $keygen = new Crypt_GPG_KeyGenerator(array(
                    'homedir' => $this->homedir,
                    // 'binary'  => '/usr/bin/gpg2',
                    'debug'   => $debug ? array($this, 'debug') : false,
            ));

            $key = $keygen
                ->setExpirationDate(0)
                ->setPassphrase($data['password'])
                ->generateKey($data['user'], $data['email']);

            return $this->parse_key($key);
        }
        catch (Exception $e) {
            return $this->get_error_from_exception($e);
        }
    }

    /**
     * Key deletion.
     *
     * @param string Key ID
     *
     * @return mixed True on success or enigma_error
     */
    public function delete_key($keyid)
    {
        // delete public key
        $result = $this->delete_pubkey($keyid);

        // error handling
        if ($result !== true) {
            $code = $result->getCode();

            // if not found, delete private key
            if ($code == enigma_error::KEYNOTFOUND) {
                $result = $this->delete_privkey($keyid);
            }
            // need to delete private key first
            else if ($code == enigma_error::DELKEY) {
                $key = $this->get_key($keyid);
                for ($i = count($key->subkeys) - 1; $i >= 0; $i--) {
                    $type = ($key->subkeys[$i]->usage & enigma_key::CAN_ENCRYPT) ? 'priv' : 'pub';
                    $result = $this->{'delete_' . $type . 'key'}($key->subkeys[$i]->id);
                    if ($result !== true) {
                        return $result;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Private key deletion.
     */
    protected function delete_privkey($keyid)
    {
        try {
            $this->gpg->deletePrivateKey($keyid);
            return true;
        }
        catch (Exception $e) {
            return $this->get_error_from_exception($e);
        }
    }

    /**
     * Public key deletion.
     */
    protected function delete_pubkey($keyid)
    {
        try {
            $this->gpg->deletePublicKey($keyid);
            return true;
        }
        catch (Exception $e) {
            return $this->get_error_from_exception($e);
        }
    }

    /**
     * Converts Crypt_GPG exception into Enigma's error object
     *
     * @param mixed Exception object
     *
     * @return enigma_error Error object
     */
    protected function get_error_from_exception($e)
    {
        $data = array();

        if ($e instanceof Crypt_GPG_KeyNotFoundException) {
            $error = enigma_error::KEYNOTFOUND;
            $data['id'] = $e->getKeyId();
        }
        else if ($e instanceof Crypt_GPG_BadPassphraseException) {
            $error = enigma_error::BADPASS;
            $data['bad']     = $e->getBadPassphrases();
            $data['missing'] = $e->getMissingPassphrases();
        }
        else if ($e instanceof Crypt_GPG_NoDataException) {
            $error = enigma_error::NODATA;
        }
        else if ($e instanceof Crypt_GPG_DeletePrivateKeyException) {
            $error = enigma_error::DELKEY;
        }
        else {
            $error = enigma_error::INTERNAL;
        }

        $msg = $e->getMessage();

        return new enigma_error($error, $msg, $data);
    }

    /**
     * Converts Crypt_GPG_Signature object into Enigma's signature object
     *
     * @param Crypt_GPG_Signature Signature object
     *
     * @return enigma_signature Signature object
     */
    protected function parse_signature($sig)
    {
        $user = $sig->getUserId();

        $data = new enigma_signature();
        $data->id          = $sig->getId();
        $data->valid       = $sig->isValid();
        $data->fingerprint = $sig->getKeyFingerprint();
        $data->created     = $sig->getCreationDate();
        $data->expires     = $sig->getExpirationDate();
        $data->name        = $user->getName();
        $data->comment     = $user->getComment();
        $data->email       = $user->getEmail();

        return $data;
    }

    /**
     * Converts Crypt_GPG_Key object into Enigma's key object
     *
     * @param Crypt_GPG_Key Key object
     *
     * @return enigma_key Key object
     */
    protected function parse_key($key)
    {
        $ekey = new enigma_key();

        foreach ($key->getUserIds() as $idx => $user) {
            $id = new enigma_userid();
            $id->name    = $user->getName();
            $id->comment = $user->getComment();
            $id->email   = $user->getEmail();
            $id->valid   = $user->isValid();
            $id->revoked = $user->isRevoked();

            $ekey->users[$idx] = $id;
        }

        $ekey->name = trim($ekey->users[0]->name . ' <' . $ekey->users[0]->email . '>');

        foreach ($key->getSubKeys() as $idx => $subkey) {
            $skey = new enigma_subkey();
            $skey->id          = $subkey->getId();
            $skey->revoked     = $subkey->isRevoked();
            $skey->created     = $subkey->getCreationDate();
            $skey->expires     = $subkey->getExpirationDate();
            $skey->fingerprint = $subkey->getFingerprint();
            $skey->has_private = $subkey->hasPrivate();
            $skey->algorithm   = $subkey->getAlgorithm();
            $skey->length      = $subkey->getLength();
            $skey->usage       = $subkey->usage();

            $ekey->subkeys[$idx] = $skey;
        };

        $ekey->id = $ekey->subkeys[0]->id;

        return $ekey;
    }

    /**
     * Write debug info from Crypt_GPG to logs/enigma
     */
    public function debug($line)
    {
        rcube::write_log('enigma', 'GPG: ' . $line);
    }
}
