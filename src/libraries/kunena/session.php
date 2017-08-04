<?php
/**
 * Kunena Component
 * @package        Kunena.Framework
 *
 * @copyright      Copyright (C) 2008 - 2017 Kunena Team. All rights reserved.
 * @license        https://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link           https://www.kunena.org
 **/
defined('_JEXEC') or die();

/**
 * Class KunenaSession
 * @since Kunena
 */
class KunenaSession extends JObject
{
	/**
	 * @var boolean
	 * @since Kunena
	 */
	protected $_exists = false;

	/**
	 * @var boolean
	 * @since Kunena
	 */
	protected $_sessiontimeout = false;

	/**
	 * @var int|string
	 * @since Kunena
	 */
	protected $allreadtime;

	/**
	 * @var
	 * @since Kunena
	 */
	private static $_instance;

	/**
	 * @param   mixed|null $identifier
	 *
	 * @since Kunena
	 */
	public function __construct($identifier)
	{
		$this->load($identifier);

		if (!$this->currvisit)
		{
			// For new users new indication displays 14 days
			$now               = \Joomla\CMS\Factory::getDate()->toUnix();
			$this->lasttime    = $now - 14 * 24 * 60 * 60; // 14 days ago
			$this->allreadtime = $this->lasttime;
			$this->currvisit   = $now;
			$this->readtopics  = 0;
		}
		else
		{
			// Deal with users who do not (yet) have all readtime set.
			$userCategory      = KunenaForumCategoryUserHelper::get(0, (int) $identifier);
			$this->allreadtime = $userCategory->allreadtime ? $userCategory->allreadtime : $this->lasttime;
		}
	}

	/**
	 * @param   bool $update
	 * @param   null $userid
	 *
	 * @return KunenaSession
	 * @since Kunena
	 */
	public static function getInstance($update = false, $userid = null)
	{
		if (!self::$_instance)
		{
			$my              = \Joomla\CMS\Factory::getUser();
			self::$_instance = new KunenaSession($userid !== null ? $userid : $my->id);

			if ($update)
			{
				self::$_instance->updateSessionInfo();
			}
		}

		return self::$_instance;
	}

	/**
	 * Method to get the session table object
	 *
	 * This function uses a static variable to store the table name of the session table to
	 * it instantiates. You can call this function statically to set the table name if
	 * needed.
	 *
	 * @access    public
	 *
	 * @param   string $type   The session table name to be used
	 * @param   string $prefix The session table prefix to be used
	 *
	 * @return    object    The session table object
	 * @since     1.5
	 */
	public function getTable($type = 'KunenaSessions', $prefix = 'Table')
	{
		static $tabletype = null;

		// Set a custom table type is defined
		if ($tabletype === null || $type != $tabletype['name'] || $prefix != $tabletype['prefix'])
		{
			$tabletype['name']   = $type;
			$tabletype['prefix'] = $prefix;
		}

		// Create the user table object
		return \Joomla\CMS\Table\Table::getInstance($tabletype['name'], $tabletype['prefix']);
	}

	/**
	 * Method to load a KunenaSession object by userid
	 *
	 * @access    public
	 *
	 * @param   int $userid The user id of the user to load
	 *
	 * @return    boolean            True on success
	 * @since     1.5
	 */
	public function load($userid)
	{
		// Create the user table object
		$table = $this->getTable();

		// Load the KunenaTableUser object based on the user id
		if ($table->load($userid))
		{
			$this->_exists = true;
		}

		// Assuming all is well at this point lets bind the data
		$this->setProperties($table->getProperties());
		$this->userid = $userid;

		return true;
	}

	/**
	 * Method to save the KunenaSession object to the database
	 *
	 * @access    public
	 *
	 * @param   boolean $updateOnly Save the object only if not a new session
	 *
	 * @return    boolean True on success
	 * @since     1.5
	 */
	public function save($updateOnly = false)
	{
		// Do not save session for anonymous users
		if (!$this->userid)
		{
			return false;
		}

		// Create the user table object
		$table = $this->getTable();
		$table->bind($this->getProperties());
		$table->exists($this->_exists);

		// Check and store the object.
		if (!$table->check())
		{
			$this->setError($table->getError());

			return false;
		}

		// Are we creating a new user
		$isnew = !$this->_exists;

		// If we aren't allowed to create new users return
		if ($isnew && $updateOnly)
		{
			return true;
		}

		// Store the user data in the database
		if (!$result = $table->store())
		{
			$this->setError($table->getError());
		}

		// Set the id for the \Joomla\CMS\User\User object in case we created a new user.
		if (empty($this->userid))
		{
			$this->userid = $table->get('userid');
		}

		// Read indication has moved outside of the session table -- let's update it too.
		$userCategory = KunenaForumCategoryUserHelper::get(0, $this->userid);

		if ($userCategory->allreadtime != $this->allreadtime)
		{
			$userCategory->allreadtime = $this->allreadtime;
			$userCategory->save();
		}

		return $result;
	}

	/**
	 * Method to delete the KunenaSession object from the database
	 *
	 * @access    public
	 * @return    boolean            True on success
	 * @since     1.5
	 */
	public function delete()
	{
		// Create the user table object
		$table = $this->getTable();

		$result = $table->delete($this->userid);

		if (!$result)
		{
			$this->setError($table->getError());
		}

		return $result;
	}

	/**
	 * @return boolean
	 * @since Kunena
	 */
	public function isNewUser()
	{
		return !$this->_exists;
	}

	/**
	 * @return boolean
	 * @since Kunena
	 */
	public function isNewSession()
	{
		// Perform session timeout check
		$lifetime              = max(intval(\Joomla\CMS\Factory::getConfig()->get('config.lifetime')) * 60, intval(KunenaFactory::getConfig()->sessiontimeout));
		$this->_sessiontimeout = ($this->currvisit + $lifetime < \Joomla\CMS\Factory::getDate()->toUnix());

		return $this->_sessiontimeout;
	}

	/**
	 * @return integer|string
	 * @since Kunena
	 */
	public function getAllReadTime()
	{
		// For existing users new indication expires after 3 months
		$monthsAgo   = \Joomla\CMS\Factory::getDate()->toUnix() - 91 * 24 * 60 * 60;
		$allreadtime = ($this->allreadtime > $monthsAgo ? $this->allreadtime : $monthsAgo);

		return $allreadtime;
	}

	/**
	 *
	 * @since Kunena
	 */
	public function markAllCategoriesRead()
	{
		$this->allreadtime = \Joomla\CMS\Factory::getDate()->toUnix();
		$this->readtopics  = 0;
	}

	/**
	 *
	 * @since Kunena
	 */
	public function updateSessionInfo()
	{
		// If this is a new session, reset the lasttime colum with the timestamp
		// of the last saved currvisit - only after that can we reset currvisit to now before the store
		if ($this->isNewSession())
		{
			$this->lasttime   = $this->currvisit;
			$this->readtopics = 0;
		}

		$this->currvisit = \Joomla\CMS\Factory::getDate()->toUnix();
	}
}
