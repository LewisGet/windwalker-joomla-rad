<?php
/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2011 - 2014 SMS Taiwan, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Windwalker\Model;

use JArrayHelper;
use JDatabaseQuery;
use Joomla\Registry\Registry;
use JPagination;
use JPluginHelper;

use Joomla\DI\Container as JoomlaContainer;

use JTable;
use Windwalker\DI\Container;
use Windwalker\Helper\PathHelper;
use Windwalker\Helper\ProfilerHelper;
use Windwalker\Model\Filter\FilterHelper;
use Windwalker\Model\Filter\SearchHelper;
use Windwalker\Model\Helper\AdminListHelper;
use Windwalker\Model\Helper\QueryHelper;
use Windwalker\Model\Provider\GridProvider;

defined('_JEXEC') or die;

/**
 * Model class for handling lists of items.
 *
 * @package     Joomla.Legacy
 * @subpackage  Model
 * @since       12.2
 */
class ListModel extends FormModel
{
	/**
	 * Internal memory based cache array of data.
	 *
	 * @var    array
	 * @since  12.2
	 */
	protected $cache = array();

	/**
	 * Valid filter fields or ordering.
	 *
	 * @var    array
	 * @since  12.2
	 */
	protected $filterFields = array();

	/**
	 * An internal cache for the last query used.
	 *
	 * @var    JDatabaseQuery
	 * @since  12.2
	 */
	protected $query = array();

	/**
	 * Name of the filter form to load
	 *
	 * @var    string
	 * @since  3.2
	 */
	protected $formPath = null;

	/**
	 * Property forms.
	 *
	 * @var
	 */
	protected $forms;

	/**
	 * Property orderCol.
	 *
	 * @var string
	 */
	protected $orderCol = null;

	/**
	 * Property searchFields.
	 *
	 * @var array
	 */
	protected $searchFields = array();

	/**
	 * Constructor
	 *
	 * @param   array              $config    An array of configuration options (name, state, dbo, table_path, ignore_request).
	 * @param   JoomlaContainer    $container Service container.
	 * @param   \JRegistry         $state     The model state.
	 * @param   \JDatabaseDriver   $db        The database adapter.
	 */
	public function __construct($config = array(), JoomlaContainer $container = null, \JRegistry $state = null, \JDatabaseDriver $db = null)
	{
		// These need before parent constructor.
		if (!$this->orderCol)
		{
			$this->orderCol = JArrayHelper::getValue($config, 'order_column', null);
		}

		if (!$this->filterFields)
		{
			$this->filterFields = JArrayHelper::getValue($config, 'filter_fields', array());

			$this->filterFields[] = '*';
		}

		// Guess name for container
		if (!$this->name)
		{
			$this->name = JArrayHelper::getValue($config, 'name', $this->getName());
		}

		$this->container = $container ? : $this->getContainer();

		$this->container->registerServiceProvider(new GridProvider($this->name));

		$this->configureTables();

		parent::__construct($config, $container, $state, $db);

		// Guess the item view as the context.
		if (empty($this->viewList))
		{
			$this->viewList = $this->getName();
		}

		// Guess the list view as the plural of the item view.
		if (empty($this->viewItem))
		{
			$inflector = \JStringInflector::getInstance();

			$this->viewItem = $inflector->toSingular($this->viewList);
		}
	}

	/**
	 * Method to get a table object, load it if necessary.
	 *
	 * @param   string  $name     The table name. Optional.
	 * @param   string  $prefix   The class prefix. Optional.
	 * @param   array   $options  Configuration array for model. Optional.
	 *
	 * @return  \JTable  A JTable object
	 *
	 * @since   3.2
	 * @throws  \Exception
	 */
	public function getTable($name = '', $prefix = '', $options = array())
	{
		$name = $name ? : $this->viewItem;

		return parent::getTable($name, $prefix, $options);
	}

	/**
	 * Method to cache the last query constructed.
	 *
	 * This method ensures that the query is constructed only once for a given state of the model.
	 *
	 * @return  JDatabaseQuery  A JDatabaseQuery object
	 *
	 * @since   12.2
	 */
	protected function _getListQuery()
	{
		// Capture the last store id used.
		static $lastStoreId;

		// Compute the current store id.
		$currentStoreId = $this->getStoreId();

		// If the last store id is different from the current, refresh the query.
		if ($lastStoreId != $currentStoreId || empty($this->query))
		{
			$lastStoreId = $currentStoreId;
			$this->query = $this->getListQuery();
		}

		return $this->query;
	}

	/**
	 * Method to get an array of data items.
	 *
	 * @return  mixed  An array of data items on success, false on failure.
	 *
	 * @since   12.2
	 */
	public function getItems()
	{
		// Get a storage key.
		$store = $this->getStoreId();

		// Try to load the data from internal storage.
		if (isset($this->cache[$store]))
		{
			return $this->cache[$store];
		}

		// Load the list items.
		$query = $this->_getListQuery();

		$items = $this->getList($query, $this->getStart(), $this->state->get('list.limit'));

		// Add the items to the internal cache.
		$this->cache[$store] = $items;

		return $this->cache[$store];
	}

	/**
	 * Method to get a JDatabaseQuery object for retrieving the data set from a database.
	 *
	 * @return  JDatabaseQuery   A JDatabaseQuery object to retrieve the data set.
	 */
	protected function getListQuery()
	{
		$query       = $this->db->getQuery(true);
		$queryHelper = $this->container->get('model.' . $this->getName() . '.helper.query');

		// Prepare
		$this->prepareGetQuery($query);

		// Build filter query
		$this->processFilters($query);

		// Build search query
		$this->processSearches($query);

		// Ordering
		$this->processOrdering($query);

		// Custom Where
		foreach ((array) $this->state->get('query.where', array()) as $k => $v)
		{
			$query->where($v);
		}

		// Custom Having
		foreach ((array) $this->state->get('query.having', array()) as $k => $v)
		{
			$query->having($v);
		}

		// Build query
		// ========================================================================

		// Get select columns
		$select = $this->state->get('query.select');

		if (!$select)
		{
			$select = $queryHelper->getSelectFields(QueryHelper::COLS_WITH_FIRST | QueryHelper::COLS_PREFIX_WITH_FIRST);
		}

		$query->select($select);

		// Build Selected tables query
		$queryHelper->registerQueryTables($query);

		$this->postGetQuery($query);

		// Debug
		ProfilerHelper::mark((string) $query);

		return $query;
	}

	/**
	 * prepareGetQuery
	 *
	 * @param JDatabaseQuery $query
	 *
	 * @return  void
	 */
	protected function prepareGetQuery(\JDatabaseQuery $query)
	{
	}

	/**
	 * postGetQuery
	 *
	 * @param JDatabaseQuery $query
	 *
	 * @return  void
	 */
	protected function postGetQuery(\JDatabaseQuery $query)
	{
	}

	/**
	 * Method to get a JPagination object for the data set.
	 *
	 * @return  JPagination  A JPagination object for the data set.
	 *
	 * @since   12.2
	 */
	public function getPagination()
	{
		// Get a storage key.
		$store = $this->getStoreId('getPagination');

		// Try to load the data from internal storage.
		if (isset($this->cache[$store]))
		{
			return $this->cache[$store];
		}

		// Create the pagination object.
		$limit = (int) $this->state->get('list.limit') - (int) $this->state->get('list.links');
		$page  = new JPagination($this->getTotal(), $this->getStart(), $limit);

		// Add the object to the internal cache.
		$this->cache[$store] = $page;

		return $this->cache[$store];
	}

	/**
	 * Method to get a store id based on the model configuration state.
	 *
	 * This is necessary because the model is used by the component and
	 * different modules that might need different sets of data or different
	 * ordering requirements.
	 *
	 * @param   string  $id  An identifier string to generate the store id.
	 *
	 * @return  string  A store id.
	 *
	 * @since   12.2
	 */
	protected function getStoreId($id = '')
	{
		// Add the list state to the store id.
		$id .= ':' . json_encode($this->filterFields);
		$id .= ':' . json_encode($this->state);

		return md5($this->context . ':' . $id);
	}

	/**
	 * Gets an array of objects from the results of database query.
	 *
	 * @param   string   $query       The query.
	 * @param   integer  $limitstart  Offset.
	 * @param   integer  $limit       The number of records.
	 *
	 * @return  array  An array of results.
	 *
	 * @since   12.2
	 * @throws  \RuntimeException
	 */
	public function getList($query, $limitstart = 0, $limit = 0)
	{
		$this->db->setQuery($query, $limitstart, $limit);

		$result = $this->db->loadObjectList();

		return $result;
	}

	/**
	 * Returns a record count for the query.
	 *
	 * @param   \JDatabaseQuery|string  $query  The query.
	 *
	 * @return  integer  Number of rows for query.
	 *
	 * @since   12.2
	 */
	public function getListCount($query)
	{
		// Use fast COUNT(*) on JDatabaseQuery objects if there no GROUP BY or HAVING clause:
		if ($query instanceof \JDatabaseQuery
			&& $query->type == 'select'
			&& $query->group === null
			&& $query->having === null)
		{
			$query = clone $query;
			$query->clear('select')->clear('order')->select('COUNT(*)');

			$this->db->setQuery($query);

			return (int) $this->db->loadResult();
		}

		// Otherwise fall back to inefficient way of counting all results.
		$this->db->setQuery($query);
		$this->db->execute();

		return (int) $this->db->getNumRows();
	}

	/**
	 * Method to get the total number of items for the data set.
	 *
	 * @return  integer  The total number of items available in the data set.
	 *
	 * @since   12.2
	 */
	public function getTotal()
	{
		// Get a storage key.
		$store = $this->getStoreId('getTotal');

		// Try to load the data from internal storage.
		if (isset($this->cache[$store]))
		{
			return $this->cache[$store];
		}

		// Load the total.
		$query = $this->_getListQuery();

		$total = (int) $this->getListCount($query);

		// Add the total to the internal cache.
		$this->cache[$store] = $total;

		return $this->cache[$store];
	}

	/**
	 * Method to get the starting number of items for the data set.
	 *
	 * @return  integer  The starting number of items available in the data set.
	 *
	 * @since   12.2
	 */
	public function getStart()
	{
		$store = $this->getStoreId('getstart');

		// Try to load the data from internal storage.
		if (isset($this->cache[$store]))
		{
			return $this->cache[$store];
		}

		$start = $this->state->get('list.start');
		$limit = $this->state->get('list.limit');
		$total = $this->getTotal();

		if ($start > $total - $limit)
		{
			$start = max(0, (int) (ceil($total / $limit) - 1) * $limit);
		}

		// Add the total to the internal cache.
		$this->cache[$store] = $start;

		return $this->cache[$store];
	}

	/**
	 * Get the filter form
	 *
	 * @param   array    $data      data
	 * @param   boolean  $loadData  load current data
	 *
	 * @return  \JForm|false  the JForm object or false
	 *
	 * @since   3.2
	 */
	public function getBatchForm($data = array(), $loadData = false)
	{
		try
		{
			return $this->loadForm($this->context . '.batch', 'batch', array('control' => '', 'load_data' => $loadData));
		}
		catch (\RuntimeException $e)
		{
			// Return Null Form
			return new \JForm($this->context . '.batch');
		}
	}

	/**
	 * Get the filter form
	 *
	 * @param   array    $data      data
	 * @param   boolean  $loadData  load current data
	 *
	 * @return  \JForm|false  the JForm object or false
	 *
	 * @since   3.2
	 */
	public function getFilterForm($data = array(), $loadData = true)
	{
		try
		{
			return $this->loadForm($this->context . '.filter', 'filter', array('control' => '', 'load_data' => $loadData));
		}
		catch (\RuntimeException $e)
		{
			// Return Null Form
			return new \JForm($this->context . '.filter');
		}
	}

	/**
	 * getQuery
	 *
	 * @return  JDatabaseQuery
	 */
	public function getQuery()
	{
		return $this->query;
	}

	/**
	 * setQuery
	 *
	 * @param   JDatabaseQuery $query
	 *
	 * @return  ListModel  Return self to support chaining.
	 */
	public function setQuery($query)
	{
		$this->query = $query;

		return $this;
	}

	/**
	 * Method to get the data that should be injected in the form.
	 *
	 * @return	mixed	The data for the form.
	 *
	 * @since	3.2
	 */
	protected function loadFormData()
	{
		// Check the session for previously entered form data.
		$app  = $this->getContainer()->get('app');
		$data = $app->getUserState($this->context, new \stdClass);

		// Pre-fill the list options
		if (!property_exists($data, 'list'))
		{
			$data->list = array(
				'direction' => $this->state->get('list.direction'),
				'limit'     => $this->state->get('list.limit'),
				'ordering'  => $this->state->get('list.ordering'),
				'start'     => $this->state->get('list.start')
			);
		}

		$data->filter = $this->state->get('filter');

		return $data;
	}

	/**
	 * Method to auto-populate the model state.
	 *
	 * This method should only be called once per instantiation and is designed
	 * to be called on the first call to the getState() method unless the model
	 * configuration flag to ignore the request is set.
	 *
	 * Note. Calling getState in this method will result in recursion.
	 *
	 * @param   string  $ordering   An optional ordering field.
	 * @param   string  $direction  An optional direction (asc|desc).
	 *
	 * @return  void
	 *
	 * @since   12.2
	 */
	protected function populateState($ordering = null, $direction = null)
	{
		$config = $this->getContainer()->get('joomla.config');

		// Set default ordering
		$this->state->set('list.direction', $direction);
		$this->state->set('list.ordering',  $ordering);

		// If the context is set, assume that stateful lists are used.
		if ($this->context)
		{
			$app = $this->container->get('app');

			// Receive & set filters
			if ($filters = $app->getUserStateFromRequest($this->context . '.filter', 'filter', array(), 'array'))
			{
				$filters = AdminListHelper::handleFilters((array) $filters, $this->filterFields);

				$this->state->set('filter', $filters);
			}

			// Receive & set searches
			if ($searches = $app->getUserStateFromRequest($this->context . '.search', 'search', array(), 'array'))
			{
				$searches = AdminListHelper::handleSearches($searches, $this->filterFields, $this->getSearchFields());

				$this->state->set('search', $searches);
			}

			$limit = 0;

			// Receive & set list options
			if ($list = $app->getUserStateFromRequest($this->context . '.list', 'list', array(), 'array'))
			{
				foreach ($list as $name => $value)
				{
					// Extra validations
					switch ($name)
					{
						case 'fullordering':
							$orderConfig = array(
								'ordering'  => $ordering,
								'direction' => $direction
							);

							$orderConfig = AdminListHelper::handleFullordering($value, $orderConfig, $this->filterFields);

							$this->state->set('list.direction', $orderConfig['direction']);
							$this->state->set('list.ordering',  $orderConfig['ordering']);
							break;

						case 'ordering':
							if (!in_array($value, $this->filterFields))
							{
								$value = $ordering;
							}
							break;

						case 'direction':
							if (!in_array(strtoupper($value), array('ASC', 'DESC', '')))
							{
								$value = $direction;
							}
							break;

						case 'limit':
							$limit = $value;
							break;

						// Just to keep the default case
						default:
							$value = $value;
							break;
					}

					$this->state->set('list.' . $name, $value);
				}
			}

			// Fill the limits and start
			if (!$limit)
			{
				$limit = $app->getUserStateFromRequest('global.list.limit', 'limit', $config->get('list_limit'), 'uint');
				$this->state->set('list.limit', $limit);
			}

			$value = $app->getUserStateFromRequest($this->context . '.limitstart', 'limitstart', 0);
			$limitstart = ($limit != 0 ? (floor($value / $limit) * $limit) : 0);
			$this->state->set('list.start', $limitstart);
		}
		else
		{
			$this->state->set('list.start', 0);
			$this->state->set('list.limit', 0);
		}
	}

	/**
	 * Method to allow derived classes to preprocess the form.
	 *
	 * @param   \JForm   $form   A JForm object.
	 * @param   mixed    $data   The data expected for the form.
	 * @param   string   $group  The name of the plugin group to import (defaults to "content").
	 *
	 * @return  void
	 *
	 * @since   3.2
	 * @throws  \Exception if there is an error in the form event.
	 */
	protected function preprocessForm(\JForm $form, $data, $group = 'content')
	{
		// Import the appropriate plugin group.
		JPluginHelper::importPlugin($group);

		// Get the dispatcher.
		$dispatcher = $this->getContainer()->get('event.dispatcher');

		// Trigger the form preparation event.
		$results = $dispatcher->trigger('onContentPrepareForm', array($form, $data));

		// Check for errors encountered while preparing the form.
		if (count($results) && in_array(false, $results, true))
		{
			// Get the last error.
			$error = $dispatcher->getError();

			if (!($error instanceof \Exception))
			{
				throw new \Exception($error);
			}
		}
	}

	/**
	 * processFilters
	 *
	 * @param JDatabaseQuery $query
	 * @param array          $filters
	 *
	 * @return  JDatabaseQuery
	 */
	protected function processFilters(JDatabaseQuery $query, $filters = array())
	{
		$filters = $filters ? : $this->state->get('filter', array());

		$filterHelper = $this->container->get('model.' . strtolower($this->name) . '.filter', Container::FORCE_NEW);

		$this->configureFilters($filterHelper);

		$query = $filterHelper->execute($query, $filters);

		return $query;
	}

	/**
	 * configureFilters
	 *
	 * @param FilterHelper $filterHelper
	 *
	 * @return  void
	 */
	protected function configureFilters($filterHelper)
	{
		// Override this method.
	}

	/**
	 * processSearches
	 *
	 * @param JDatabaseQuery $query
	 * @param array          $searches
	 *
	 * @return  JDatabaseQuery
	 */
	protected function processSearches(JDatabaseQuery $query, $searches = array())
	{
		$searches = $searches ? : $this->state->get('search', array());

		$searchHelper = $this->container->get('model.' . strtolower($this->name) . '.search', Container::FORCE_NEW);

		$this->configureSearches($searchHelper);

		$query = $searchHelper->execute($query, $searches);

		return $query;
	}

	/**
	 * configureSearches
	 *
	 * @param SearchHelper $searchHelper
	 *
	 * @return  void
	 */
	protected function configureSearches($searchHelper)
	{
		// Override this method.
	}

	/**
	 * processOrdering
	 *
	 * @param JDatabaseQuery $query
	 * @param null           $ordering
	 * @param null           $direction
	 *
	 * @return  void
	 */
	protected function processOrdering(JDatabaseQuery $query, $ordering = null, $direction = null)
	{
		$ordering  = $ordering  ? : $this->state->get('list.ordering',  'ordering' /*$this->Viewitem . '.ordering'*/);
		$direction = $direction ? : $this->state->get('list.direction', 'ASC');
		$ordering  = explode(',', $ordering);

		// Add quote
		$ordering = array_map(
			function($value) use($query)
			{
				$value = explode(' ', trim($value));

				// $value[1] is direction
				if (isset($value[1]))
				{
					return $query->quoteName($value[0]) . ' ' . $value[1];
				}

				return $query->quoteName($value[0]);
			},
			$ordering
		);

		$ordering = implode(', ', $ordering);

		$query->order($ordering . ' ' . $direction);
	}

	/**
	 * getFullSearchFields
	 *
	 * @return  array
	 */
	public function getSearchFields()
	{
		if ($this->searchFields)
		{
			return $this->searchFields;
		}

		$file = PathHelper::get($this->option) . '/model/form/' . $this->name . '/filter.xml';

		if (!is_file($file))
		{
			return array();
		}

		$xml     = simplexml_load_file($file);
		$field   = $xml->xpath('//fields[@name="search"]/field[@name="field"]');

		$options = $field[0]->option;
		$fields  = array();

		foreach ($options as $option)
		{
			$attr = $option->attributes();

			if ('*' == (string) $attr['value'])
			{
				continue;
			}

			$fields[] = (string) $attr['value'];
		}

		return $this->searchFields = $fields;
	}

	/**
	 * Gets the value of a user state variable and sets it in the session
	 *
	 * This is the same as the method in JApplication except that this also can optionally
	 * force you back to the first page when a filter has changed
	 *
	 * @param   string   $key        The key of the user state variable.
	 * @param   string   $request    The name of the variable passed in a request.
	 * @param   string   $default    The default value for the variable if not found. Optional.
	 * @param   string   $type       Filter for the variable, for valid values see {@link \JFilterInput::clean()}. Optional.
	 * @param   boolean  $resetPage  If true, the limitstart in request is set to zero
	 *
	 * @return  array The request user state.
	 *
	 * @since   12.2
	 */
	public function getUserStateFromRequest($key, $request, $default = null, $type = 'none', $resetPage = true)
	{
		$app       = $this->container->get('app');
		$input     = $app->input;
		$old_state = $app->getUserState($key);
		$cur_state = (!is_null($old_state)) ? $old_state : $default;
		$new_state = $input->get($request, null, $type);

		if (($cur_state != $new_state) && ($resetPage))
		{
			$input->set('limitstart', 0);
		}

		// Save the new value only if it is set in this request.
		if ($new_state !== null)
		{
			$app->setUserState($key, $new_state);
		}
		else
		{
			$new_state = $cur_state;
		}

		return $new_state;
	}

	/**
	 * configureTables
	 *
	 * @return  void
	 */
	protected function configureTables()
	{
	}

	/**
	 * addTable
	 *
	 * @param string $alias
	 * @param string $table
	 * @param mixed  $condition
	 * @param string $joinType
	 *
	 * @return  ListModel
	 */
	public function addTable($alias, $table, $condition = null, $joinType = 'LEFT')
	{
		$queryHelper = $this->getContainer()->get('model.' . $this->name . '.helper.query');

		$queryHelper->addTable($alias, $table, $condition, $joinType);

		return $this;
	}

	/**
	 * removeTable
	 *
	 * @param string $alias
	 *
	 * @return  $this
	 */
	public function removeTable($alias)
	{
		$queryHelper = $this->getContainer()->get('model.' . $this->name . '.helper.query');

		$queryHelper->removeTable($alias);

		return $this;
	}
}
