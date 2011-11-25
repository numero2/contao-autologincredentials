<?php if (!defined('TL_ROOT')) die('You can not access this file directly!');

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2010 Leo Feyer
 *
 * Formerly known as TYPOlight Open Source CMS.
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 * @copyright  2011 numero2 - Agentur für Internetdienstleistungen
 * @author     numero2 (http://www.numero2.de)
 * @package    Registration
 * @license    LGPL
 */


class AutoLoginCredentials extends ModuleRegistration {


	/**
	 * Creates username and password if needed
	 * @param array
	 */
	public function createLoginCredentials( &$arrData ) {
    
        // create a username
        if( empty($arrData['username']) ) {
        
            $arrData['username'] = 'u'.substr(md5(uniqid(mt_rand(), true)),0,4);
        }
        
        // create a username
        if( empty($arrData['password']) ) {
        
            $arrData['password'] = 'p'.substr(md5(uniqid(mt_rand(), true)),0,$GLOBALS['TL_CONFIG']['minPasswordLength']);
        }
	}
    

	/**
	 * Create a new user and redirect
	 * @param array
	 */
	protected function createNewUser($arrData)
	{
		$arrData['tstamp'] = time();
		$arrData['login'] = $this->reg_allowLogin;
		$arrData['activation'] = md5(uniqid(mt_rand(), true));
		$arrData['dateAdded'] = $arrData['tstamp'];

        if( $this->reg_createLoginCredentials )
            $this->createLoginCredentials($arrData);

		// Set default groups
		if (!array_key_exists('groups', $arrData))
		{
			$arrData['groups'] = $this->reg_groups;
		}

		// Disable account
		$arrData['disable'] = 1;

		// Send activation e-mail
		if ($this->reg_activate)
		{
			$arrChunks = array();

			$strConfirmation = $this->reg_text;
			preg_match_all('/##[^#]+##/i', $strConfirmation, $arrChunks);

			foreach ($arrChunks[0] as $strChunk)
			{
				$strKey = substr($strChunk, 2, -2);

				switch ($strKey)
				{
					case 'domain':
						$strConfirmation = str_replace($strChunk, $this->Environment->host, $strConfirmation);
						break;

					case 'link':
						$strConfirmation = str_replace($strChunk, $this->Environment->base . $this->Environment->request . (($GLOBALS['TL_CONFIG']['disableAlias'] || strpos($this->Environment->request, '?') !== false) ? '&' : '?') . 'token=' . $arrData['activation'], $strConfirmation);
						break;

					// HOOK: support newsletter subscriptions
					case 'channel':
					case 'channels':
						if (!in_array('newsletter', $this->Config->getActiveModules()))
						{
							break;
						}

						// Make sure newsletter is an array
						if (!is_array($arrData['newsletter']))
						{
							if ($arrData['newsletter'] != '')
							{
								$arrData['newsletter'] = array($arrData['newsletter']);
							}
							else
							{
								$arrData['newsletter'] = array();
							}
						}

						// Replace the wildcard
						if (count($arrData['newsletter']) > 0)
						{
							$objChannels = $this->Database->execute("SELECT title FROM tl_newsletter_channel WHERE id IN(". implode(',', array_map('intval', $arrData['newsletter'])) .")");
							$strConfirmation = str_replace($strChunk, implode("\n", $objChannels->fetchEach('title')), $strConfirmation);
						}
						else
						{
							$strConfirmation = str_replace($strChunk, '', $strConfirmation);
						}
						break;

					default:
						$strConfirmation = str_replace($strChunk, $arrData[$strKey], $strConfirmation);
						break;
				}
			}

			$objEmail = new Email();

			$objEmail->from = $GLOBALS['TL_ADMIN_EMAIL'];
			$objEmail->fromName = $GLOBALS['TL_ADMIN_NAME'];
			$objEmail->subject = sprintf($GLOBALS['TL_LANG']['MSC']['emailSubject'], $this->Environment->host);
			$objEmail->text = $strConfirmation;
			$objEmail->sendTo($arrData['email']);
		}
        
        // replace password in database with encrypted version after mail is sent
        if( $this->reg_createLoginCredentials ) {
        
            // ony encrypt password if its not already encrypted
            if( !empty($arrData['password']) && strpos($arrData['password'],':') != 40 ) {
            
                $strSalt = substr(md5(uniqid(mt_rand(), true)), 0, 23);
                $arrData['password'] = sha1($strSalt . $arrData['password']) . ':' . $strSalt;
                
                $this->Database->prepare("UPDATE tl_member SET password=? WHERE id=?")->execute($arrData['password'], $insertId);                
            }
        }

		// Make sure newsletter is an array
		if (isset($arrData['newsletter']) && !is_array($arrData['newsletter']))
		{
			$arrData['newsletter'] = array($arrData['newsletter']);
		}

		// Create user
		$objNewUser = $this->Database->prepare("INSERT INTO tl_member %s")->set($arrData)->execute();
		$insertId = $objNewUser->insertId;

		// Assign home directory
		if ($this->reg_assignDir && is_dir(TL_ROOT . '/' . $this->reg_homeDir))
		{
			$this->import('Files');
			$strUserDir = strlen($arrData['username']) ? $arrData['username'] : 'user_' . $insertId;

			// Add the user ID if the directory exists
			if (is_dir(TL_ROOT . '/' . $this->reg_homeDir . '/' . $strUserDir))
			{
				$strUserDir .= '_' . $insertId;
			}

			new Folder($this->reg_homeDir . '/' . $strUserDir);

			$this->Database->prepare("UPDATE tl_member SET homeDir=?, assignDir=1 WHERE id=?")
						   ->execute($this->reg_homeDir . '/' . $strUserDir, $insertId);
		}

		// HOOK: send insert ID and user data
		if (isset($GLOBALS['TL_HOOKS']['createNewUser']) && is_array($GLOBALS['TL_HOOKS']['createNewUser']))
		{
			foreach ($GLOBALS['TL_HOOKS']['createNewUser'] as $callback)
			{
				$this->import($callback[0]);
				$this->$callback[0]->$callback[1]($insertId, $arrData);
			}
		}

		// Inform admin if no activation link is sent
#		if (!$this->reg_activate)
#		{
			$this->sendAdminNotification($insertId, $arrData);
#		}

		$this->jumpToOrReload($this->jumpTo);
	}
}

