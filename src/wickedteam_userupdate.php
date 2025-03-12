<?php

/**
 * Content Plugin.
 *
 * @package    Wickedteam_Userupdate
 * @subpackage Plugin
 * @author     Heinl Christian <heinchrs@gmail.com>
 * @copyright  (C) 2013-2025 Heinl Christian
 * @license    GNU General Public License version 2 or later
 * @abstract   This plugin notifies about changed or newly added Wickedteam field
 *             values. Therefore a email address must be configured via plugin
 *             parameter 'notification_email' where the notification email
 *             should be sent to.
 *             Additionally this plugin updates the email address of the
 *             assigned Joomla user when the Wickedteam email address will be
 *             changed accordingly.
 *             Therefore the plugin has to know in which Wickedteam field the
 *             email addresses are stored. If this field is created as a
 *             copy-field, only the content of the base field is used as the new
 *             email address for the associated Joomla user.
 *             If no Joomla user is assigned to the Wickedteam member no update
 *             is performed.
 */

// -- No direct access
defined('_JEXEC') || die('=;)');

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Factory;
use Joomla\CMS\Mail\MailHelper;


/**
 * Plugin class which implements the plugin behavior
 *
 * @author  Heinl Christian <heinchrs@gmail.com>
 * @since 1.0
 */
class PlgContentWickedteam_Userupdate extends CMSPlugin
{
	/**
	 * Array which holds all sections a Wickedteam member is assigned to
	 * @var array
	 */
	private $assignedSections;

	/**
	 * The application object
	 * @var JApplication
	 */
	protected $app;

	/**
	 * Constructor
	 *
	 * @param   object $subject The object to observe
	 * @param   array  $config  An optional associative array of configuration settings.
	 */
	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		$this->app = Factory::getApplication();

		// Load language file for plugin frontend
		$this->loadLanguage();
	}

	/**
	 * This is an event that is called right before the content is saved into
	 * the database. You can abort the save by returning false.
	 *
	 * @param   string   $context    The context of the content being passed to the plugin - this is the component name and view
	 *                               or name of module (e.g. com_content.article).
	 *                               Use this to check whether you are in the desired context for the plugin.
	 * @param   object   $content    A reference to the JTableContent object that is being saved which holds the article data.
	 * @param   bool     $isNew      A boolean which is set to true if the content is about to be created.
	 * @return  void
	 */
	public function onContentAfterSave($context, $content, $isNew)
	{
		// Don't run this plugin when a save is done not from Wickedteam component
		if ($context != 'com_wickedteam.wickedteam' && $context != 'com_wickedteam.member')
		{
			return;
		}

		/**
		 *************************************************************************
		 * Notification about changed Wickedteam member data
		 *************************************************************************
		 */
		$recipient = $this->params->get('notification_email', '');

		// Convert mail addresses separated by comma into array umwandeln and remove spaces
		$recipientArray = array_map('trim', explode(',', $recipient));
		$invalidMailAddressFound = false;
		$invalidMailAddress = "";

		// Check if valid email is set in plugin parameter for sending Wickedteam change notifications
		foreach ($recipientArray as $email)
		{
			if (!MailHelper::isEmailAddress($email))
			{
				$invalidMailAddressFound = true;
				$invalidMailAddress = $email;
				break;
			}
		}

		if (!$invalidMailAddressFound)
		{
			$notify = $this->params->get('send_notification', '');

			/**
			 * 0=don't send emails
			 * 1=send emails while data change has been done in frontend or backend
			 * 2=send emails while data change has been done in frontend
			 * 3=send emails while data change has been done in backend
			 */
			if (($notify == 1 && ($this->app->isClient('site') || $this->app->isClient('administrator')))
				|| ($notify == 2 && $this->app->isClient('site'))
				|| ($notify == 3 && $this->app->isClient('administrator')))
			{
				$this->sendUpdateNotifiction($content, $isNew);
			}
		}
		else
		{
			$buffer = JText::sprintf('PLG_WICKEDTEAM_USERUPDATE_INVALID_EMAIL_ADDRESS', $invalidMailAddress, "Wickedteam Userupdate");
			$this->app->enqueueMessage($buffer, 'warning');
		}

		/**
		 ***************************************************************************
		 * Adaption of joomla user email, if assigned Wickedteam user mail has changed
		 ***************************************************************************
		 */
		// Only update joomla user data if wickedteam member is assigned to joomla user and if user is not newly entered
		if ($content->user_id != 0 && !$isNew && $this->params->get('update_joomla_user_email', '') == 1)
		{
			$this->updateJoomlaUserData($content);
		}
	}

	/**
	 * This method checks for changed or new Wickedteam member data and informs
	 * via email about the new or changed data.
	 *
	 * @param   JTableContent $content  A reference to the JTableContent object that is being saved which holds the article data.
	 * @param   boolean       $isNew    A boolean which is set to true if the content is about to be created.
	 * @return  void
	 */
	private function sendUpdateNotifiction($content, $isNew)
	{
		$db = Factory::getDbo();
		$query = $db->getQuery(true);

		$user = Factory::getUser();
		$mailer = Factory::getMailer();
		$config = Factory::getConfig();

		// Fetch the field values which were submitted by Wickedteam edit form
		$jinput = $this->app->input->get('jform', array(), 'array');


		// Get all Wickedteam fields with its id and title and put it into an associative array
		$query->clear();
		$query->select('a.title, a.id');
		$query->from('#__wickedteam_fields AS a');
		$query->order('a.id');
		$db->setQuery($query);
		$results = $db->loadAssoclist();

		// Initialize the dictionary (associative array)
		$WickedteamMemberFields = array();

		// Populate the dictionary with id as the key and title as the value
		foreach ($results as $row)
		{
			$WickedteamMemberFields[$row['id']] = $row['title'];
		}

		/**
		 **************************************************************************
		 * Email notification about changed member data
		 **************************************************************************
		 */
		// If Wickedteam member data was changed
		if (!$isNew)
		{
			/*
			 * Setup query to get old member values
			 * When onContentAfterSave is called the new values are not yet saved in database
			 * First process Wickedteam field values (assigned groups are processed later)
			 */
			$query->clear();
			$query->select('f.value, f.instance, a.title, a.alias, a.id');
			$query->from('#__wickedteam_member_field_values AS f');
			$query->leftjoin('#__wickedteam_fields AS a ON a.id = f.field_id');
			$query->where('f.member_id = ' . $content->id);
			$query->order('a.id');
			$db->setQuery($query);

			$WickedteamMemberData = $db->loadAssoclist();


			// print("<pre>");
			// print_r($query->dump());
			// print_r($WickedteamMemberData);

			// print_r($jinput);
			// print("</pre>"); //die();


			/*
			 * Generate array which holds the old data in a associative array which has
			 * the following key syntax: alias_0_0=>value
			 * The last number is incremented for each entry of the same alias
			 */
			$oldData = array();

			foreach ($WickedteamMemberData as $data)
			{
				$iInstance = $data['instance'];
				$iIndex = 0;

				while (array_key_exists("field-" . $data['id'] . '_' . $iInstance . '_' . $iIndex, $oldData))
				{
					$iIndex++;
				}

				$oldData["field-" . $data['id'] . '_' . $iInstance . '_' . $iIndex] = $data['value'];
			}


			// print("<pre>");
			// print_r($oldData);
			// print("</pre>"); //die();


			$iChangeCounter = 0;
			$buffer = "";
			$regex = "#field-(\d+?)_\d+?_\d+?#s";
			$matches = array();

			foreach ($jinput as $key => $value)
			{
				// If value itself is an array (e.g. if field is a select type) then the values are stored separated by "\n" in database
				if (is_array($value))
				{
					// Convert array to string separated by "\n"
					$value = implode("\n", $value);
				}

				// If values has changed and field is user defined(contains 'field-number_number_number', e.g. field-1_0_0)
				if (preg_match($regex, $key, $matches) && (!array_key_exists($key, $oldData)|| $oldData[$key] != $value))
				{
					// If value is 0 and old value is empty then skip this field
					// This is necessary because the Wickedteam component saves empty fields as 0
					if($value == 0 && (!array_key_exists($key, $oldData) || $oldData[$key] == ""))
					{
						continue;
					}

					$iChangeCounter++;

					/**
					 * Variable matches[1] contains the field id without '_number_number' postfix
					 * in case of array data converted to strings divided by "\n" the "\n" are replaced by ","
					 */
					$buffer = $buffer . $WickedteamMemberFields[$matches[1]] . ": " .
								 str_replace("\n", ", ", $oldData[$key]) . " => " . str_replace("\n", ", ", $value) . "\n";
				}
			}

			$this->checkSectionAssignment($content->id, $iChangeCounter, $buffer);
		}
		else
		{
			$iChangeCounter = 1;
			$id_lastname = $this->params->get('lastname_field', '');
			$id_firstname = $this->params->get('firstname_field', '');

			$buffer = $jinput['field-' . $id_lastname . '_0_0'] . " " . $jinput['field-' . $id_firstname . '_0_0'];
		}

		if ($iChangeCounter > 0)
		{
			// Setup email stuff
			$sender = array($config->get('mailfrom'), $config->get('fromname'));

			$mailer->addReplyTo($sender[0], $sender[1]);
			$mailer->setSender($sender[0], $sender[1]);


			$recipient = $this->params->get('notification_email', '');
			// Convert mail addresses separated by comma into array umwandeln and remove spaces
			$recipientArray = array_map('trim', explode(',', $recipient));

			if (is_array($recipient))
			{
				$mailer->addRecipient($recipient[0], $recipient[1]);
			}
			else
			{
				$mailer->addRecipient($recipient);
			}

			setlocale(LC_CTYPE, "de_DE.UTF-8");

			/**
			 * $string = "Deutsche Umlaute Ä Ö Ü ä ö ü ß";
			 * $string = iconv('UTF-8', 'ASCII//TRANSLIT', $buffer);
			 * echo $string;  die();
			 * print $buffer;die();
			 */
			$id_lastname = $this->params->get('lastname_field', '');
			$id_firstname = $this->params->get('firstname_field', '');

			$body = JText::sprintf($isNew ? 'PLG_WICKEDTEAM_USERUPDATE_MEMBER_DATA_ADDED' : 'PLG_WICKEDTEAM_USERUPDATE_MEMBER_DATA_CHANGED', $user->name, $user->username, $user->email, iconv("UTF-8", "ASCII//TRANSLIT", $jinput['field-' . $id_lastname . '_0_0'] . " " . $jinput['field-' . $id_firstname . '_0_0']), iconv("UTF-8", "ASCII//TRANSLIT", $buffer));

			$subject = JText::sprintf($isNew ? 'PLG_WICKEDTEAM_USERUPDATE_SUBJECT_MEMBER_DATA_ADDED' : 'PLG_WICKEDTEAM_USERUPDATE_SUBJECT_MEMBER_DATA_CHANGED');

			//print "<pre>";
			//print_r($body);die();
			//print "</pre>";

			if ($this->params->get('debug_output', '') == 0)
			{
				// Set email subject
				$mailer->setSubject($subject);

				// Set email body while converting all characters that can't be represented in the target charset,
				// are approximated through one or several similarly looking characters.
				$mailer->setBody($body);

				// Send email
				$mailer->Send();
			}
		}

		// If debug mode is selected
		if ($this->params->get('debug_output', '') == 1)
		{
			if($iChangeCounter > 0)
			{
				$buffer = JText::sprintf('PLG_WICKEDTEAM_USERUPDATE_DEBUG_OUTPUT',$subject, nl2br($body));
				$this->app->enqueueMessage($buffer, 'info');
			}
			else
			{
				$this->app->enqueueMessage(JText::_('PLG_WICKEDTEAM_USERUPDATE_DEBUG_OUTPUT_NO_CHANGES'), 'info');
			}
		}
	}

	/**
	 * This method updates the email-address of assigned Joomla user to the changed
	 * Wickedteam email-address. The Wickedteam field which holds the email-adress is configured via
	 * a plugin parameter which is named 'email_field'.
	 *
	 * @param   JTableContent $content A reference to the JTableContent object that is being saved which holds the article data.
	 * @return  void
	 */
	private function updateJoomlaUserData($content)
	{
		$db = Factory::getDbo();
		$query = $db->getQuery(true);

		// Fetch the field values which were submitted by Wickedteam edit form
		$jinput = $this->app->input->get('jform', array(), 'array');

		// Get plugin parameter for Wickedteam field which is used for storing member emails
		$mailField = $this->params->get('email_field', '');

		$query->clear();

		// Get current email address of assigned joomla user
		$query->select('email');
		$query->from('#__users');
		$query->where('id = ' . $content->user_id);

		// Limit data to one record
		$db->setQuery($query, 0, 1);
		$result = $db->loadAssoc();

		// Get value of the currently stored email field (base field is used)
		$newWickedteamEmail = $jinput['field-' . $mailField . '_0_0'];

		// Get the value of the currently assigned joomla member email address
		$joomlaEmail = $result['email'];

		// Get the user id of assigned Joomla user of current Wickedteam member
		$joomlaUserId = $content->user_id;

		// Check if new Wickedteam email address is a valid email address

		if (!MailHelper::isEmailAddress($newWickedteamEmail))
		{
			$this->app->enqueueMessage(JText::_('PLG_WICKEDTEAM_USERUPDATE_INVALID_EMAIL'), 'warning');

			return;
		}

		// Only if joomla email address and new Wickedteam email address differs
		if ($newWickedteamEmail != $joomlaEmail)
		{
			$query->clear();

			// Fields to update.
			$fields = array($db->quoteName('email') . '=\'' . $newWickedteamEmail . '\'');

			// Conditions for which records should be updated.
			$conditions = array($db->quoteName('id') . '=' . $joomlaUserId);

			$query->update($db->quoteName('#__users'))->set($fields)->where($conditions);
			$db->setQuery($query);
			$db->query();

			$this->app->enqueueMessage(JText::_('PLG_WICKEDTEAM_USERUPDATE_EMAIL_UPDATED'), 'info');
		}
	}

	/**
	 * This method checks which section and section_fees assignment of a Wickedteam
	 * user was changed. All changed values are returned as a string via the
	 * reference parameter $buffer.
	 *
	 * @param   integer $memberId       Wickedteam member id
	 * @param   integer $iChangeCounter Number of changed section assignments
	 * @param   string  $buffer         String which holds all changed section assignments
	 *
	 * @return  void
	 */
	private function checkSectionAssignment($memberId, &$iChangeCounter, &$buffer)
	{
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		$query->clear();

		// Get configuration parameter of Wickedteam component
		$wickedteamParams = JComponentHelper::getParams('com_wickedteam');

		// Fetch the field values which were submitted by Wickedteam edit form
		$jinput = $this->app->input->get('jform', array(), 'array');

		// First get all sections to which the member has belonged to
		$query->select('c.id, c.title, c.alias');
		$query->from('#__wickedteam_member_category AS m');
		$query->leftjoin('#__categories AS c ON c.id = m.catid');
		$query->where('m.member_id = ' . $memberId);
		$query->order('c.id');
		$db->setQuery($query);
		$wickedteamOldSectionData = $db->loadAssoclist();

		/**
		 * print("<pre>");
		 * print_r($wickedteamOldSectionData);
		 * print("</pre>"); die();
		 */

		// If 'sections' key exists in array (only if user is allowed to change section assignments)
		if (array_key_exists('sections', $jinput))
		{
			// Get all section names, the member belongs now to
			$query->clear();
			$query->select('id,title, alias');
			$query->from('#__categories');
			$query->where('id in (' . implode(',', $jinput['sections']) . ')');
			$query->order('id');
			$db->setQuery($query);
			$wickedteamNewSectionData = $db->loadAssoclist();
		}

		elseif ($wickedteamParams->get('edit_club_section') == 0)
		{
			// New section assignment is equal to old section assignment
			$wickedteamNewSectionData = $wickedteamOldSectionData;
		}

		/** print("<pre>");
		 * print_r($wickedteamNewSectionData);
		 * print("</pre>"); die();
		 */

		// Array to hold new added member sections
		$added = array();

		// Array to hold deleted member sections
		$deleted = array();

		if (count($wickedteamOldSectionData) > 0)
		{
			// Loop over old section data and compare it with new section data in order to detect deleted section assignments
			foreach ($wickedteamOldSectionData as $old)
			{
				$found = false;

				// Loop over new section data
				foreach ($wickedteamNewSectionData as $new)
				{
					// If section was already assigned in old member data
					if ($old['id'] == $new['id'])
					{
						$found = true;
						break;
					}
				}

				// If old section id was not found in new section data --> section was deleted
				if (!$found)
				{
					$deleted[] = $old['title'];
				}
			}
		}

		// Loop over new section data and compare it with old section data in order to detect added section assignments
		foreach ($wickedteamNewSectionData as $new)
		{
			$found = false;

			// Store currently assigned sections in member array needed for checking section fee assignment
			$this->assignedSections[] = $new['id'];

			if (count($wickedteamOldSectionData) > 0)
			{
				// Loop over old section data
				foreach ($wickedteamOldSectionData as $old)
				{
					// If section was already assigned in old member data
					if ($new['id'] == $old['id'])
					{
						$found = true;
						break;
					}
				}
			}

			// If new section id was not found in old section data --> section was added
			if (!$found)
			{
				$added[] = $new['title'];
			}
		}

		$iChangeCounter += count($deleted) + count($added);

		foreach ($deleted as $deletedSection)
		{
			$buffer = $buffer . JText::_('PLG_WICKEDTEAM_USERUPDATE_SECTION') . ": \"" . $deletedSection . "\" " . JText::_('PLG_WICKEDTEAM_USERUPDATE_NOT_ASSIGNED') . "\n";
		}

		foreach ($added as $addedSection)
		{
			$buffer = $buffer . JText::_('PLG_WICKEDTEAM_USERUPDATE_SECTION') . ": \"" . $addedSection . "\" " . JText::_('PLG_WICKEDTEAM_USERUPDATE_ASSIGNED') . "\n";
		}
	}
}
