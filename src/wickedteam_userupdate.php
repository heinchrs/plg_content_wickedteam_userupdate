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
	public function onContentBeforeSave($context, $content, $isNew)
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

		// Get all Wickedteam fields with its id, name and title and put it into associative arrays
		$query->clear();
		$query->select('a.title, a.id, a.name, a.type');
		$query->from('#__fields AS a');
		$query->where("a.context='com_wickedteam.member'");
		$query->order('a.id');
		$db->setQuery($query);
		$results = $db->loadAssoclist();

		// Initialize the dictionaries (associative arrays)
		$WickedteamMemberFieldsByName = array(); // name => ['id'=>..,'title'=>..,'type'=>..]
		$WickedteamMemberFieldsById = array();   // id => name
		$WickedteamMemberFieldTypeById = array(); // id => type

		// Populate the dictionaries by field name and id
		foreach ($results as $row)
		{
			if (!empty($row['name']))
			{
				$WickedteamMemberFieldsByName[$row['name']] = array('id' => $row['id'], 'title' => $row['title'], 'type' => $row['type']);
				$WickedteamMemberFieldsById[$row['id']] = $row['name'];
				$WickedteamMemberFieldTypeById[$row['id']] = $row['type'];
			}
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
			$query->select('f.value, a.title, a.name, a.id');
			$query->from('#__fields_values AS f');
			$query->leftjoin('#__fields AS a ON a.id = f.field_id');
			$query->where('f.item_id = ' . $content->id);
			$query->order('a.id');
			$db->setQuery($query);

			$WickedteamMemberData = $db->loadAssoclist();

			// Build name-indexed old-data map for Joomla 4 fields, to compare against jform['com_fields']
			$oldDataByName = array();
			$fieldTitleByName = array();
			foreach ($WickedteamMemberData as $d)
			{
				if (!empty($d['name']))
				{
					$val = $d['value'];
					$dec = json_decode($val, true);
					$oldDataByName[$d['name']] = (json_last_error() === JSON_ERROR_NONE) ? $dec : $val;
					$fieldTitleByName[$d['name']] = $d['title'];
				}
			}

			$iChangeCounter = 0;
			$buffer = "";

			if (isset($jinput['com_fields']) && is_array($jinput['com_fields']))
			{
				foreach ($jinput['com_fields'] as $fname => $fval)
				{
					$old = array_key_exists($fname, $oldDataByName) ? $oldDataByName[$fname] : "";
					$fieldType = isset($WickedteamMemberFieldsByName[$fname]['type']) ? $WickedteamMemberFieldsByName[$fname]['type'] : '';

					$normalizedOld = $this->normalizeWickedteamFieldValue($fname, $old, $fieldType, $WickedteamMemberFieldTypeById);
					$normalizedNew = $this->normalizeWickedteamFieldValue($fname, $fval, $fieldType, $WickedteamMemberFieldTypeById);

					$oldS = is_array($normalizedOld) ? json_encode($normalizedOld) : (string) $normalizedOld;
					$newS = is_array($normalizedNew) ? json_encode($normalizedNew) : (string) $normalizedNew;

					if ($oldS !== $newS)
					{
						if ($newS === "0" && $oldS === "")
						{
							continue;
						}

						$iChangeCounter++;
						$title = isset($fieldTitleByName[$fname]) ? $fieldTitleByName[$fname] : $fname;
								$buffer .= $title . ": " . $this->buildWickedteamFieldChangeDescription($normalizedOld, $normalizedNew, $WickedteamMemberFieldsById) . "\n";
					}
				}
			}

			// print("<pre>");
			// print_r($buffer);
			// print("</pre>"); die();

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
			$memberName = trim($this->getWickedteamFieldValueById($jinput, $id_lastname, $WickedteamMemberFieldsById) . ' ' . $this->getWickedteamFieldValueById($jinput, $id_firstname, $WickedteamMemberFieldsById));

			$body = JText::sprintf($isNew ? 'PLG_WICKEDTEAM_USERUPDATE_MEMBER_DATA_ADDED' : 'PLG_WICKEDTEAM_USERUPDATE_MEMBER_DATA_CHANGED', $user->name, $user->username, $user->email, iconv("UTF-8", "ASCII//TRANSLIT", $memberName), iconv("UTF-8", "ASCII//TRANSLIT", $buffer));

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
	 * Normalizes a Wickedteam field value for comparison.
	 *
	 * Handles nested repeatable values and normalizes field values by type
	 * (dates, phone numbers, JSON structures, text, etc.).
	 *
	 * @param   string  $fieldName    The field name or nested key being normalized.
	 * @param   mixed   $value        The raw field value.
	 * @param   string  $fieldType    The field type used for type-specific normalization.
	 * @param   array   $fieldTypeMap Mapping of field IDs to field types for nested keys.
	 * @return  mixed  The normalized field value.
	 */
	private function normalizeWickedteamFieldValue($fieldName, $value, $fieldType = '', array $fieldTypeMap = array())
	{
		$fieldType = strtolower((string) $fieldType);

		if (is_array($value))
		{
			$normalized = array();
			foreach ($value as $key => $item)
			{
				$nestedFieldType = $fieldType;
				if (preg_match('/^field(\d+)$/', $key, $matches))
				{
					$nestedFieldType = $this->getWickedteamFieldTypeById($matches[1], $fieldTypeMap);
				}

				$normalized[$key] = $this->normalizeWickedteamFieldValue($key, $item, $nestedFieldType, $fieldTypeMap);
			}

			return $normalized;
		}

		$value = (string) $value;
		$fieldType = strtolower($fieldType);

		if (in_array($fieldType, array('calendar', 'date', 'datetime', 'datetime-local'), true))
		{
			return $this->normalizeWickedteamFieldDate($value);
		}

		if (in_array($fieldType, array('tel', 'phone', 'phonenumber'), true))
		{
			return $this->normalizePhoneNumber($value);
		}

		if (in_array($fieldType, array('text', 'textarea'), true))
		{
			return $value;
		}

		$value = trim($value);
		$value = preg_replace('/\s+/', ' ', $value);

		$json = json_decode($value, true);
		if (json_last_error() === JSON_ERROR_NONE)
		{
			return $this->normalizeWickedteamFieldValue($fieldName, $json, $fieldType, $fieldTypeMap);
		}

		return $value;
	}

	/**
	 * Builds a human-readable change description for a single field.
	 *
	 * If the value is an array, a nested array diff is generated.
	 * Otherwise the old and new scalar values are rendered for comparison.
	 *
	 * @param   mixed  $oldValue      The original field value.
	 * @param   mixed  $newValue      The new field value.
	 * @param   array  $fieldIdToName A map from field IDs to field names for repeatable keys.
	 * @return  string
	 */
	private function buildWickedteamFieldChangeDescription($oldValue, $newValue, array $fieldIdToName = array())
	{
		if (is_array($oldValue) || is_array($newValue))
		{
			return $this->buildWickedteamArrayChangeDescription((array) $oldValue, (array) $newValue, $fieldIdToName);
		}

		return $this->stringifyWickedteamValue($oldValue) . ' => ' . $this->stringifyWickedteamValue($newValue);
	}

	/**
	 * Builds a change description for array values and nested repeatable rows.
	 *
	 * Only changed subkeys are included in the result, with field IDs
	 * resolved to configured field names where possible.
	 *
	 * @param   array  $oldArray      The original array value.
	 * @param   array  $newArray      The new array value.
	 * @param   array  $fieldIdToName Map from field IDs to field names.
	 * @return  string
	 */
	private function buildWickedteamArrayChangeDescription(array $oldArray, array $newArray, array $fieldIdToName = array())
	{
		$keys = array_unique(array_merge(array_keys($oldArray), array_keys($newArray)));
		$changes = array();

		foreach ($keys as $key)
		{
			$oldItem = array_key_exists($key, $oldArray) ? $oldArray[$key] : null;
			$newItem = array_key_exists($key, $newArray) ? $newArray[$key] : null;

			if ($this->areWickedteamValuesEqual($oldItem, $newItem))
			{
				continue;
			}

			if (is_array($oldItem) || is_array($newItem))
			{
				$changes[] = $key . ': ' . $this->renderWickedteamRowContent((array) $oldItem, $fieldIdToName) . ' => ' . $this->renderWickedteamRowContent((array) $newItem, $fieldIdToName);
			}
			else
			{
				$changes[] = $key . ': ' . $this->stringifyWickedteamValue($oldItem) . ' => ' . $this->stringifyWickedteamValue($newItem);
			}
		}

		return !empty($changes) ? implode('; ', $changes) : $this->stringifyWickedteamValue($oldArray) . ' => ' . $this->stringifyWickedteamValue($newArray);
	}

	/**
	 * Compares two Wickedteam values for equality.
	 *
	 * This handles both scalar values and nested arrays recursively.
	 *
	 * @param   mixed  $a
	 * @param   mixed  $b
	 * @return  bool
	 */
	private function areWickedteamValuesEqual($a, $b)
	{
		if (is_array($a) || is_array($b))
		{
			return $this->areWickedteamArraysEqual((array) $a, (array) $b);
		}

		return (string) $a === (string) $b;
	}

	/**
	 * Recursively compares two Wickedteam arrays for equality.
	 *
	 * This is used when normalizing and diffing repeatable values.
	 *
	 * @param   array  $a
	 * @param   array  $b
	 * @return  bool
	 */
	private function areWickedteamArraysEqual(array $a, array $b)
	{
		if (count($a) !== count($b))
		{
			return false;
		}

		foreach ($a as $key => $value)
		{
			if (!array_key_exists($key, $b) || !$this->areWickedteamValuesEqual($value, $b[$key]))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Renders a repeatable row as a human-readable string.
	 *
	 * Field key names like field24 are replaced with configured field names
	 * when a mapping is available.
	 *
	 * @param   array  $row           The row data to render.
	 * @param   array  $fieldIdToName Field ID to name mapping.
	 * @return  string
	 */
	private function renderWickedteamRowContent(array $row, array $fieldIdToName = array())
	{
		$parts = array();

		foreach ($row as $key => $value)
		{
			$displayKey = $this->resolveWickedteamFieldDisplayName($key, $fieldIdToName);

			if (is_array($value))
			{
				$parts[] = $displayKey . '=(' . $this->renderWickedteamRowContent($value, $fieldIdToName) . ')';
			}
			else
			{
				$parts[] = $displayKey . '="' . $this->escapeWickedteamString((string) $value) . '"';
			}
		}

		return implode(', ', $parts);
	}

	/**
	 * Resolves a nested repeatable field key to its configured field name.
	 *
	 * Example: field24 becomes the actual field name for ID 24.
	 *
	 * @param   string  $fieldKey      The nested field key.
	 * @param   array   $fieldIdToName Map from field IDs to field names.
	 * @return  string
	 */
	private function resolveWickedteamFieldDisplayName($fieldKey, array $fieldIdToName = array())
	{
		if (preg_match('/^field(\d+)$/', $fieldKey, $matches))
		{
			$fieldId = $matches[1];
			if (isset($fieldIdToName[$fieldId]))
			{
				return $fieldIdToName[$fieldId];
			}
		}

		return $fieldKey;
	}

	/**
	 * Converts a Wickedteam value into a printable scalar string.
	 *
	 * Arrays are JSON encoded, while scalars are cleaned of control characters.
	 *
	 * @param   mixed  $value
	 * @return  string
	 */
	private function stringifyWickedteamValue($value)
	{
		if (is_array($value))
		{
			return json_encode($value);
		}

		return str_replace(array("\r", "\n", "\t"), array('', ' ', ' '), (string) $value);
	}

	/**
	 * Escapes a string for inline output in a Wickedteam row description.
	 *
	 * Removes line breaks and tabs while preserving the visible value.
	 *
	 * @param   mixed  $value
	 * @return  string
	 */
	private function escapeWickedteamString($value)
	{
		return str_replace(array("\r", "\n", "\t"), array('', ' ', ' '), trim((string) $value));
	}

	/**
	 * Normalizes date values to a stable YYYY-MM-DD format.
	 *
	 * Supports several source formats used by Wickedteam fields.
	 *
	 * @param   mixed  $value
	 * @return  string
	 */
	private function normalizeWickedteamFieldDate($value)
	{
		$value = trim((string) $value);
		if ($value === '')
		{
			return '';
		}

		$formats = array('Y-m-d H:i:s', 'Y-m-d', 'd.m.Y', 'd.m.Y H:i:s', '\Y-m-d\TH:i:sP', '\Y-m-d\TH:i:s');
		foreach ($formats as $format)
		{
			$date = DateTime::createFromFormat($format, $value);
			if ($date && $date->format($format) === $value)
			{
				return $date->format('Y-m-d');
			}
		}

		$date = date_create($value);
		if ($date)
		{
			return $date->format('Y-m-d');
		}

		return $value;
	}

	/**
	 * Normalizes phone numbers by removing non-digit and non-plus characters.
	 *
	 * @param   mixed  $value
	 * @return  string
	 */
	private function normalizePhoneNumber($value)
	{
		return preg_replace('/[^0-9+]/', '', (string) $value);
	}

	/**
	 * Returns the configured Wickedteam field type for a nested field ID.
	 *
	 * @param   mixed  $fieldId
	 * @param   array  $fieldTypeMap
	 * @return  string
	 */
	private function getWickedteamFieldTypeById($fieldId, array $fieldTypeMap = array())
	{
		$fieldId = (string) $fieldId;
		return isset($fieldTypeMap[$fieldId]) ? $fieldTypeMap[$fieldId] : '';
	}

	/**
	 * Reads a Wickedteam field value by field ID from submitted input.
	 *
	 * Supports both modern Joomla field name arrays and legacy field-<id> keys.
	 *
	 * @param   array   $jinput        The submitted jform array.
	 * @param   mixed   $fieldId       The field ID to resolve.
	 * @param   array   $fieldIdToName Optional map from field IDs to field names.
	 * @return  mixed
	 */
	private function getWickedteamFieldValueById(array $jinput, $fieldId, array $fieldIdToName = array())
	{
		$fieldId = (string) $fieldId;
		if ($fieldId === '')
		{
			return '';
		}

		if (!empty($fieldIdToName[$fieldId]) && isset($jinput['com_fields'][$fieldIdToName[$fieldId]]))
		{
			return $jinput['com_fields'][$fieldIdToName[$fieldId]];
		}

		$key = 'field-' . $fieldId . '_0_0';
		return isset($jinput[$key]) ? $jinput[$key] : '';
	}

	/**
	 * Updates the Joomla user's email address when the assigned Wickedteam email field changes.
	 *
	 * The field used for the Wickedteam email address is configured via the
	 * plugin parameter 'email_field'. If the new Wickedteam email differs from
	 * the current Joomla email and is valid, the Joomla user record is updated.
	 *
	 * @param   JTableContent $content The current Wickedteam content item.
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
		$mailFieldValue = $this->getWickedteamFieldValueById($jinput, $mailField);

		$query->clear();

		// Get current email address of assigned joomla user
		$query->select('email');
		$query->from('#__users');
		$query->where('id = ' . $content->user_id);

		// Limit data to one record
		$db->setQuery($query, 0, 1);
		$result = $db->loadAssoc();

		// Get value of the currently stored email field (base field is used)
		$newWickedteamEmail = $mailFieldValue;

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
		$query->where('c.published = 1');
		$query->order('c.id');
		$db->setQuery($query);
		$wickedteamOldSectionData = $db->loadAssoclist();

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
		else
		{
			// New section assignment is equal to old section assignment
			$wickedteamNewSectionData = $wickedteamOldSectionData;
		}

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
