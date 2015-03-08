<?php


namespace IPS\rules\modules\admin\rules;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * admin
 */
class _rulesets extends \IPS\Node\Controller
{
	/**
	 * Node Class
	 */
	protected $nodeClass = '\IPS\rules\Rule\Ruleset';
	
	/**
	 * @brief	If true, root cannot be turned into sub-items, and other items cannot be turned into roots
	 */
	protected $protectRoots = TRUE;
	
	/**
	 * @brief	If true, will prevent any item from being moved out of its current parent, only allowing them to be reordered within their current parent
	 */
	protected $lockParents = TRUE;
	
	/**
	 * Title can contain HTML?
	 */
	public $_titleHtml = TRUE;
	
	/**
	 * Description can contain HTML?
	 */
	public $_descriptionHtml = TRUE;
	
	/**
	 * Constructor
	 *
	 * @param	\IPS\Http\Url|NULL	$url		The base URL for this controller or NULL to calculate automatically
	 * @return	void
	 */
	public function __construct( $url=NULL )
	{
		parent::__construct( $url );
	}
	
	/**
	 * Execute
	 *
	 * @return	void
	 */
	public function execute()
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'rules_manage' );
		
		parent::execute();
	}
	
	/**
	 * Manage
	 */
	protected function manage()
	{
		\IPS\Output::i()->sidebar[ 'actions' ][ 'exportall' ] = array(
			'icon'	=> 'download',
			'link'	=> \IPS\Http\Url::internal( 'app=rules&module=rules&controller=rulesets&do=exportAll' ),
			'title'	=> 'rules_export_all',
			'data' => array( 'confirm' => '' ),
		);
		
		\IPS\Output::i()->output .= "<style> #tree_search { display:none; } </style>";
		
		parent::manage();
		
		$rulesClass		= '\IPS\rules\Rule';
		$rulesController 	= new \IPS\rules\modules\admin\rules\rules( NULL );
		$rules 			= new \IPS\Helpers\Tree\Tree( 
						\IPS\Http\Url::internal( "app=rules&module=rules&controller=rules&rule={$this->id}" ),
						$rulesClass::$nodeTitle, 
						array( $rulesController, '_getRoots' ), 
						array( $rulesController, '_getRow' ), 
						array( $rulesController, '_getRowParentId' ), 
						array( $rulesController, '_getChildren' ), 
						array( $rulesController, '_getRootButtons' )
					);
		
		if ( ! \IPS\Request::i()->isAjax() )
		{
			\IPS\Output::i()->output .= (string) $rules;
		}
		
	}
	 
	/**
	 * Add/Edit Form
	 *
	 * @return void
	 */
	protected function form()
	{	
		$rule 	= NULL;
		$parent = NULL;
		
		if ( \IPS\Request::i()->id )
		{
			if ( \IPS\Request::i()->subnode )
			{
				$rule = \IPS\rules\Rule::load( \IPS\Request::i()->id );
				\IPS\Output::i()->output .= \IPS\rules\Application::eventHeader( $rule->event() );
			}
		}
		
		if ( $rule and $rule->parent() )
		{
			$parent = $rule->parent();
		}
		else if ( \IPS\Request::i()->parent and \IPS\Request::i()->subnode == 0 )
		{
			/**
			 * Setting nodeClass to \rules\Rule because otherwise the logic in the parent::form()
			 * gives us the form for a new rule set instead of a new rule
			 */
			$this->nodeClass = '\IPS\rules\Rule';
			$parent = \IPS\rules\Rule::load( \IPS\Request::i()->parent );
			\IPS\Output::i()->output .= \IPS\rules\Application::eventHeader( $parent->event() );
		}
		
		if ( $parent )
		{
			\IPS\Output::i()->output .= \IPS\rules\Application::ruleChild( $parent );
		}
		
		parent::form();		
	}
	
	/**
	 * Get Child Rows
	 *
	 * Modified because _getChildren() from stock node controller doesn't account for
	 * the ID having an "s." prefix for subnodes
	 *
	 * @param	int|string	$id		Row ID
	 * @return	array
	 */
	public function _getChildren( $id )
	{
		$rows = array();

		$nodeClass = $this->nodeClass;
		if ( mb_substr( $id, 0, 2 ) == 's.' )
		{
			$nodeClass = $nodeClass::$subnodeClass;
			$id = mb_substr( $id, 2 );
		}

		try
		{
			$node	= $nodeClass::load( $id );
		}
		catch( \OutOfRangeException $e )
		{
			\IPS\Output::i()->error( 'node_error', '2S101/R', 404, '' );
		}

		foreach ( $node->children( NULL ) as $child )
		{
			$id = ( $child instanceof $this->nodeClass ? '' : 's.' ) . $child->_id;
			$rows[ $id ] = $this->_getRow( $child );
		}
		return $rows;
	}
	
	/**
	 * Get Root Buttons
	 *
	 * @return	array
	 */
	public function _getRootButtons()
	{
		$buttons = array
		(
			'add_rule' => array
			(
				'icon' => 'plus',
				'title' => 'rulesets_add_child',
				'link' => $this->url->setQueryString( array( 'do' => 'form', 'subnode' => 1 ) ),
			),
		);
	
	
		$buttons = array_merge( $buttons, parent::_getRootButtons() );
		
		if ( isset ( $buttons[ 'add' ] ) )
		{
			$buttons[ 'add' ][ 'icon' ] = 'legal';
			$buttons[ 'add' ][ 'title' ] = 'rulesets_add';
		}
		
		$buttons[ 'import' ]  = array
		(
			'icon'	=> 'upload',
			'title'	=> 'import',
			'link'	=> $this->url->setQueryString( array( 'do' => 'import' ) ),
			'data'	=> array( 'ipsDialog' => '', 'ipsDialog-title' => \IPS\Member::loggedIn()->language()->addToStack( 'import' ) )
		);
		
		return $buttons;
	}
	
	/**
	 * Get Single Row
	 *
	 * @param	mixed	$id		May be ID number (or key) or an \IPS\Node\Model object
	 * @param	bool	$root	Format this as the root node?
	 * @param	bool	$noSort	If TRUE, sort options will be disabled (used for search results)
	 * @return	string
	 */
	public function _getRow( $id, $root=FALSE, $noSort=FALSE )
	{
		$nodeClass = $this->nodeClass;
		if ( $id instanceof \IPS\Node\Model )
		{
			$node = $id;
		}
		else
		{
			try
			{
				$node = $nodeClass::load( $id );
			}
			catch( \OutOfRangeException $e )
			{
				\IPS\Output::i()->error( 'node_error', '2S101/P', 404, '' );
			}
		}
		
		$id = ( $node instanceof $nodeClass ) ? $node->_id :  "s.{$node->_id}";
		$class = get_class( $node );
		
		$buttons = $node->getButtons( $this->url, !( $node instanceof $this->nodeClass ) );
		if ( isset( \IPS\Request::i()->searchResult ) and isset( $buttons['edit'] ) )
		{
			$buttons['edit']['link'] = $buttons['edit']['link']->setQueryString( 'searchResult', \IPS\Request::i()->searchResult );
		}
		
		$title = $node->_title;
		
		if ( $node instanceof \IPS\rules\Rule )
		{
			if ( $node->hasChildren() )
			{
				$title = "<span class='ipsBadge ipsBadge_warning'>Rule Group</span> " . $title;
			}
			else
			{
				$title = "<span class='ipsBadge ipsBadge_neutral'>Rule</span> " . $title;
			}
		}
		else if ( $node instanceof \IPS\rules\Rule\Ruleset )
		{
			$title = "<span class='ipsBadge ipsBadge_positive'>Rule Set</span> " . $title;
		}
										
		return \IPS\Theme::i()->getTemplate( 'trees', 'core' )->row(
			$this->url,
			$id,
			$title,
			$node->childrenCount( NULL ),
			$buttons,
			$node->_description,
			$node->_icon ? $node->_icon : NULL,
			( $noSort === FALSE and $class::$nodeSortable and $node->canEdit() ) ? $node->_position : NULL,
			$root,
			$node->_enabled,
			( $node->_locked or !$node->canEdit() ),
			( ( $node instanceof \IPS\Node\Model ) ? $node->_badge : $this->_getRowBadge( $node ) ),
			$this->_titleHtml,
			$this->_descriptionHtml,
			$node->canAdd()
		);
	}

	/**
	 * Redirect after save
	 *
	 * @param	\IPS\Node\Model	$old	A clone of the node as it was before or NULL if this is a creation
	 * @param	\IPS\Node\Model	$node	The node now
	 * @return	void
	 */
	protected function _afterSave( \IPS\Node\Model $old = NULL, \IPS\Node\Model $new )
	{
		if( \IPS\Request::i()->isAjax() )
		{
			\IPS\Output::i()->json( array() );
		}
		else
		{
			if ( isset ( \IPS\Request::i()->subnode ) )
			{
				if ( $old == NULL )
				{
					\IPS\Output::i()->redirect( \IPS\Http\Url::internal( "app=rules&module=rules&controller=rulesets&subnode=1&do=form&id={$new->id}&tab=conditions" ) );
				}
			}
		}
		
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( "app=rules&module=rules&controller=rulesets" ), 'saved' );
	}
	
	/**
	 * View Rules Log Info
	 */
	protected function viewlog()
	{
		try
		{
			$log = \IPS\Db::i()->select( '*', 'rules_logs', array( 'id=?', \IPS\Request::i()->logid ) )->first();
		}
		catch( \UnderflowException $e )
		{
			\IPS\Output::i()->error( 'Log Not Found', '2RL01/A', 403 );
		}
		
		$self = $this;
		$event = \IPS\rules\Event::load( $log[ 'app' ], $log[ 'class' ], $log[ 'key' ] );
		
		$rule = NULL;
		try
		{
			$rule = \IPS\rules\Rule::load( $log[ 'rule_id' ] );
		}
		catch( \OutOfRangeException $e ) {}
		
		$conditions		= new \IPS\Helpers\Table\Db( 'rules_logs', $this->url, array( 'thread=? AND type=? AND rule_id=?', $log[ 'thread' ], 'IPS\rules\Condition', $log[ 'rule_id' ] ) );
		$conditions->include 	= array( 'op_id', 'message', 'result' );
		$conditions->langPrefix = 'rules_conditions_table_';
		$conditions->parsers 	= array
		(
			'op_id'	=> function( $val )
			{
				try
				{
					$operation = \IPS\rules\Condition::load( $val );
					return $operation->title;
				}
				catch ( \OutOfRangeException $e )
				{
					return 'Unknown Condition (deleted)';
				}
			},
			'result' => function( $val )
			{
				if ( $json_val = json_decode( $val ) )
				{
					return "<pre>" . print_r( $json_val, true ) . "</pre>";
				}				
				return $val;
			},
		);				
		$conditions->sortBy = 'id';
		$conditions->sortDirection = 'asc';
		$conditions->noSort = array( 'op_id', 'message', 'result' );
		
		$actions		= new \IPS\Helpers\Table\Db( 'rules_logs', $this->url, array( 'thread=? AND type=? AND rule_id=?', $log[ 'thread' ], 'IPS\rules\Action', $log[ 'rule_id' ] ) );
		$actions->include 	= array( 'op_id', 'message', 'result', 'time' );
		$actions->langPrefix 	= 'rules_actions_table_';
		$actions->parsers 	= array
		(
			'op_id'	=> function( $val )
			{
				try
				{
					$operation = \IPS\rules\Action::load( $val );
					return $operation->title;
				}
				catch ( \OutOfRangeException $e )
				{
					return 'Unknown Action (deleted)';
				}
			},
			'result' => function( $val )
			{
				if ( $json_val = json_decode( $val ) )
				{
					return "<pre>" . print_r( $json_val, true ) . "</pre>";
				}				
				return $val;
			},
			'time'	=> function( $val )
			{
				return (string) \IPS\DateTime::ts( $val );
			},
		);				
		$actions->sortBy = 'id';
		$actions->sortDirection = 'asc';
		$actions->noSort = array( 'op_id', 'message', 'result' );
		
		$subrules		= new \IPS\Helpers\Table\Db( 'rules_logs', $this->url, array( 'thread=? AND op_id=0 AND rule_parent=?', $log[ 'thread' ], $log[ 'rule_id' ] ) );
		$subrules->include 	= array( 'rule_id', 'message', 'result' );
		$subrules->langPrefix 	= 'rules_subrules_table_';
		$subrules->parsers 	= array
		(
			'rule_id' => function( $val )
			{
				try
				{
					$rule = \IPS\rules\Rule::load( $val );
					return $rule->title;
				}
				catch ( \OutOfRangeException $e )
				{
					return 'Unknown Rule (deleted)';
				}
			},
			'result' => function( $val )
			{
				if ( $json_val = json_decode( $val ) )
				{
					return "<pre>" . print_r( $json_val, true ) . "</pre>";
				}				
				return $val;
			},
		);				
		$subrules->sortBy = 'id';
		$subrules->sortDirection = 'asc';
		$subrules->rowButtons = function( $row ) use ( $self )
		{	
			$buttons = array();
			try
			{
				$rule = \IPS\rules\Rule::load( $row[ 'rule_id' ] );
				if ( $rule->debug )
				{
					$buttons[ 'debug' ] = array(
						'icon'		=> 'bug',
						'title'		=> 'View Debug Console',
						'id'		=> "{$row['id']}-view",
						'link'		=> $self->url->setQueryString( array( 'do' => 'form', 'id' => $row[ 'rule_id' ], 'tab' => 'debug_console' ) ),
					);
				}
			}
			catch ( \OutOfRangeException $e ) {}
			
			return $buttons;
		};
		$subrules->noSort = array( 'rule_id', 'message', 'result' );
				
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'views' )->logdetails( $log, $event, $rule, $conditions, $actions, $subrules );
	}
	
	/**
	 * Export Rule(set)
	 */
	protected function export()
	{
		/**
		 * Export A Single Rule
		 */
		if ( \IPS\Request::i()->rule )
		{
			try
			{
				$rule = \IPS\rules\Rule::load( \IPS\Request::i()->rule );
			}
			catch( \OutOfRangeException $e )
			{
				\IPS\Output::i()->error( 'invalid_rule', '2RL01/B', 403 );
			}
			
			$title 		= $rule->title;
			$xml 		= \IPS\Xml\SimpleXML::create( 'ruledata' );
			$rulesets 	= $xml->addChild( 'rulesets' );
			$rules 		= $xml->addChild( 'rules' );
			$customActions 	= $xml->addChild( 'customActions' );
			
			$custom_actions = $this->_addRuleExport( $rule, $rules );
			
			foreach ( $custom_actions as $custom_action )
			{
				$this->_addCustomActionExport( $custom_action, $customActions );
			}
		}
		
		/**
		 * Export A Whole Rule Set
		 */
		else
		{
			try
			{
				$set = \IPS\rules\Rule\Ruleset::load( \IPS\Request::i()->ruleset );
			}
			catch ( \OutOfRangeException $e )
			{
				\IPS\Output::i()->error( 'invalid_rule', '2RL01/B', 403 );
			}
			
			$title 		= $set->title;
			$xml 		= \IPS\Xml\SimpleXML::create( 'ruledata' );
			$rulesets 	= $xml->addChild( 'rulesets' );
			$rules 		= $xml->addChild( 'rules' );
			$customActions 	= $xml->addChild( 'customActions' );
			
			$custom_actions = $this->_addRulesetExport( $set, $rulesets );
			
			foreach ( $custom_actions as $custom_action )
			{
				$this->_addCustomActionExport( $custom_action, $customActions );
			}
		}
		
		\IPS\Output::i()->sendOutput( $xml->asXML(), 200, 'application/xml', array( "Content-Disposition" => \IPS\Output::getContentDisposition( 'attachment', \IPS\Http\Url::seoTitle( $title ) . '.xml' ) ) );
	}
	
	/**
	 * Export All Rule(set)s
	 */
	protected function exportAll()
	{
		$xml = \IPS\Xml\SimpleXML::create( 'ruledata' );
		$rulesets 	= $xml->addChild( 'rulesets' );
		$rules 		= $xml->addChild( 'rules' );
		$customActions 	= $xml->addChild( 'customActions' );
		$custom_actions	= array();
		
		foreach ( \IPS\rules\Rule\Ruleset::roots( NULL ) as $ruleset )
		{
			$custom_actions = array_merge( $custom_actions, $this->_addRulesetExport( $ruleset, $rulesets ) );
		}
		
		foreach ( \IPS\rules\Rule::roots( NULL, NULL, array( array( 'rule_ruleset_id=0' ) ) ) as $rule )
		{
			$custom_actions = array_merge( $custom_actions, $this->_addRuleExport( $rule, $rules ) );
		}
		
		foreach ( \IPS\rules\Action\Custom::roots( NULL ) as $custom_action )
		{
			$this->_addCustomActionExport( $custom_action, $customActions );
		}
		
		\IPS\Output::i()->sendOutput( $xml->asXML(), 200, 'application/xml', array( "Content-Disposition" => \IPS\Output::getContentDisposition( 'attachment', \IPS\Http\Url::seoTitle( 'rules-export-' . \IPS\DateTime::ts( time() ) ) . '.xml' ) ) );
	}
	
	/**
	 * Build Rule Nodes
	 *
	 * @param 	array			$rules		Rule to export
	 * @param	\IPS\Xml\SimpleXML	$xml		XML object
	 */
	protected function _addRulesetExport( $ruleset, $xml )
	{	
		$rulesetNode = $xml->addChild( 'ruleset' );
		$rulesetNode->addAttribute( 'title', 	$ruleset->title );
		$rulesetNode->addAttribute( 'weight', 	$ruleset->weight );
		$rulesetNode->addAttribute( 'enabled', 	$ruleset->enabled );
		$rulesetNode->addAttribute( 'created', 	$ruleset->created_time );
		$rulesetNode->addAttribute( 'creator', 	$ruleset->creator );
		
		$rulesetNode->addChild( 'description', 	$ruleset->description );
		$custom_actions = array();
		
		$rulesNode = $rulesetNode->addChild( 'rules' );
		foreach ( $ruleset->children() as $rule )
		{
			$custom_actions = array_merge( $custom_actions, $this->_addRuleExport( $rule, $rulesNode ) );
		}
		
		return $custom_actions;
	}

	/**
	 * Export Rule Nodes
	 *
	 * @param 	array			$rules		Rule to export
	 * @param	\IPS\Xml\SimpleXML	$xml		XML object
	 */
	protected function _addRuleExport( $rule, $xml )
	{			
		$ruleNode = $xml->addChild( 'rule' );
		$ruleNode->addAttribute( 'title', 	$rule->title );
		$ruleNode->addAttribute( 'weight', 	$rule->weight );
		$ruleNode->addAttribute( 'enabled', 	$rule->enabled );
		$ruleNode->addAttribute( 'app', 	$rule->event_app );
		$ruleNode->addAttribute( 'class', 	$rule->event_class );
		$ruleNode->addAttribute( 'key',		$rule->event_key );
		$ruleNode->addAttribute( 'compare',	$rule->base_compare );
		$ruleNode->addAttribute( 'debug',	FALSE );
		
		$custom_actions = array();
		
		$conditionsNode = $ruleNode->addChild( 'conditions' );
		foreach ( $rule->conditions() as $condition )
		{
			$this->_addConditionExport( $condition, $conditionsNode );
		}
		
		$actionsNode = $ruleNode->addChild( 'actions' );
		foreach ( $rule->actions() as $action )
		{
			/* Export custom actions that we want to trigger */
			$custom_actions = array_merge( $custom_actions, $this->_addActionExport( $action, $actionsNode ) );
		}
		
		$subrulesNode = $ruleNode->addChild( 'rules' );
		foreach ( $rule->children() as $subrule )
		{
			$this->_addRuleExport( $subrule, $subrulesNode );
		}
		
		/**
		 * Export any custom action that this rule is triggered by
		 */
		if 
		( 
			$rule->parent_id == 0 and
			$rule->event_app == 'rules' and
			$rule->event_class == 'CustomActions'
		)
		{
			$custom_action_key = mb_substr( $rule->event_key, \strlen( 'custom_action_' ) );
			try
			{
				$custom_action = \IPS\rules\Action\Custom::load( $custom_action_key, 'custom_action_key' );
				$custom_actions[ $custom_action_key ] = $custom_action;
			}
			catch ( \OutOfRangeException $e ) {}
		}
			
		return $custom_actions;
	}
	
	/**
	 * Export Condition Nodes
	 *
	 * @param 	\IPS\rules\Condition	$condition	Condition to export
	 * @param	\IPS\Xml\SimpleXML	$xml		XML object
	 */
	protected function _addConditionExport( $condition, $xml )
	{
		$conditionNode = $xml->addChild( 'condition' );
		
		$conditionNode->addAttribute( 'title', 		$condition->title );
		$conditionNode->addAttribute( 'weight', 	$condition->weight );
		$conditionNode->addAttribute( 'rule', 		$condition->rule_id );
		$conditionNode->addAttribute( 'app', 		$condition->app );
		$conditionNode->addAttribute( 'class', 		$condition->class );
		$conditionNode->addAttribute( 'key', 		$condition->key );
		$conditionNode->addAttribute( 'enabled',	$condition->enabled );
		$conditionNode->addAttribute( 'compare', 	$condition->group_compare );
		$conditionNode->addAttribute( 'not',		$condition->not );
		
		$conditionNode->addChild( 'data', json_encode( $condition->data ) );
		
		$subconditions = $conditionNode->addChild( 'conditions' );
		foreach( $condition->children() as $_condition )
		{
			$this->_addConditionExport( $_condition, $subconditions );
		}
	}
	
	/**
	 * Export Action Nodes
	 *
	 * @param 	\IPS\rules\Action	$action		Action to export
	 * @param	\IPS\Xml\SimpleXML	$xml		XML object
	 */
	protected function _addActionExport( $action, $xml )
	{
		$actionNode 	= $xml->addChild( 'action' );
		$custom_actions = array();
		
		$actionNode->addAttribute( 'title', 		$action->title );
		$actionNode->addAttribute( 'weight', 		$action->weight );
		$actionNode->addAttribute( 'rule', 		$action->rule_id );
		$actionNode->addAttribute( 'app', 		$action->app );
		$actionNode->addAttribute( 'class', 		$action->class );
		$actionNode->addAttribute( 'key', 		$action->key );
		$actionNode->addAttribute( 'enabled',		$action->enabled );
		$actionNode->addAttribute( 'description', 	$action->description );
		$actionNode->addAttribute( 'schedule_mode',	$action->schedule_mode );
		$actionNode->addAttribute( 'schedule_date', 	$action->schedule_date );
		$actionNode->addAttribute( 'schedule_minutes', 	$action->schedule_minutes );
		$actionNode->addAttribute( 'schedule_hours', 	$action->schedule_hours );
		$actionNode->addAttribute( 'schedule_days',	$action->schedule_days );
		$actionNode->addAttribute( 'schedule_months',	$action->schedule_months );
		
		$actionNode->addChild( 'schedule_customcode', $action->schedule_customcode );
		$actionNode->addChild( 'data', json_encode( $action->data ) );
		
		/**
		 * Export any custom action that this action wants to trigger
		 */
		if 
		( 
			$action->app == 'rules' and
			$action->class == 'CustomActions'
		)
		{
			$custom_action_key = mb_substr( $action->key, \strlen( 'custom_action_' ) );
			try
			{
				$custom_action = \IPS\rules\Action\Custom::load( $custom_action_key, 'custom_action_key' );
				$custom_actions[ $custom_action_key ] = $custom_action;
			}
			catch ( \OutOfRangeException $e ) {}
		}
			
		return $custom_actions;		
	}
	
	/**
	 * Export Custom Actions
	 *
	 * @param 	\IPS\rules\Action\Custom	$action		Custom action to export
	 * @param	\IPS\Xml\SimpleXML		$xml		XML object
	 */
	protected function _addCustomActionExport( $action, $xml )
	{
		$actionNode 	= $xml->addChild( 'action' );

		$actionNode->addAttribute( 'title', 		$action->title );
		$actionNode->addAttribute( 'weight', 		$action->weight );
		$actionNode->addAttribute( 'description',	$action->description );
		$actionNode->addAttribute( 'key', 		$action->key );
		
		$argumentsNode = $actionNode->addChild( 'arguments' );
		foreach ( $action->children() as $argument )
		{
			$this->_addArgumentExport( $argument, $argumentsNode );
		}
	}
	
	/**
	 * Export Custom Action Arguments
	 *
	 * @param 	\IPS\rules\Action\Argument	$argument	Argument to export
	 * @param	\IPS\Xml\SimpleXML		$xml		XML object
	 */
	protected function _addArgumentExport( $argument, $xml )
	{
		$argumentNode 	= $xml->addChild( 'argument' );
		
		$argumentNode->addAttribute( 'name',		$argument->name );
		$argumentNode->addAttribute( 'type',		$argument->type );
		$argumentNode->addAttribute( 'class',		$argument->class );
		$argumentNode->addAttribute( 'required',	$argument->required );
		$argumentNode->addAttribute( 'weight',		$argument->weight );
		$argumentNode->addAttribute( 'custom_class',	$argument->custom_class );
		$argumentNode->addAttribute( 'description',	$argument->description );
		$argumentNode->addAttribute( 'varname',		$argument->varname );
	}

	/**
	 * Import Form
	 *
	 * @return	void
	 */
	public function import()
	{		
		if ( \IPS\NO_WRITES )
		{
			\IPS\Output::i()->error( 'no_writes', '1RI00/A', 403, '' );
		}

		$form = new \IPS\Helpers\Form( NULL, 'import' );
		
		if ( isset( \IPS\Request::i()->id ) )
		{
			$form->hiddenValues['id'] = \IPS\Request::i()->id;
		}
		
		$form->add( new \IPS\Helpers\Form\Upload( 'rules_import', NULL, TRUE, array( 'allowedFileTypes' => array( 'xml' ), 'temporary' => TRUE ) ) );
		
		if ( $values = $form->values() )
		{
			$tempFile = tempnam( \IPS\TEMP_DIRECTORY, 'IPS' );
			move_uploaded_file( $values[ 'rules_import' ], $tempFile );
								
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=rules&module=rules&controller=rulesets&do=doImport&file=' . urlencode( $tempFile ) . '&key=' . md5_file( $tempFile ) . ( isset( \IPS\Request::i()->id ) ? '&id=' . \IPS\Request::i()->id : '' ) ) );
		}
		
		/* Display */
		\IPS\Output::i()->output = $form;
	}
	
	/**
	 * Import
	 *
	 * @return	void
	 */
	public function doImport()
	{
		if ( ! file_exists( \IPS\Request::i()->file ) or md5_file( \IPS\Request::i()->file ) !== \IPS\Request::i()->key )
		{
			\IPS\Output::i()->error( 'generic_error', '2RI00/B', 500, '' );
		}
		
		if ( ! ( $import = \simplexml_load_file( \IPS\Request::i()->file, 'SimpleXMLElement', LIBXML_NOCDATA ) ) )
		{
			\IPS\Output::i()->error( 'xml_upload_invalid', '2RI00/C', 403, '' );
		}
		
		/**
		 * Import Rulesets
		 */
		if ( $import->rulesets->ruleset )
		{
			foreach ( $import->rulesets->ruleset as $rulesetXML )
			{
				$this->_constructNewRuleset( $rulesetXML );
			}
		}
		
		/**
		 * Import Independent Rules
		 */
		if ( $import->rules->rule )
		{
			foreach ( $import->rules->rule as $ruleXML )
			{
				$this->_constructNewRule( $ruleXML, 0, 0 );
			}
		}
		
		/**
		 * Import Custom Actions
		 */
		if ( $import->customActions->action )
		{
			foreach ( $import->customActions->action as $actionXML )
			{
				$this->_constructNewCustomAction( $actionXML );
			}
		}
		
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( "app=rules&module=rules&controller=rulesets" ), 'rules_imported' );
		
	}
	
	/**
	 * Create A Ruleset From XML
	 */
	protected function _constructNewRuleset( $rulesetXML )
	{
		$ruleset = new \IPS\rules\Rule\Ruleset;
		
		$ruleset->title 	= (string) 	$rulesetXML[ 'title' ];
		$ruleset->weight 	= (int) 	$rulesetXML[ 'weight' ];
		$ruleset->enabled	= (int) 	$rulesetXML[ 'enabled' ];
		$ruleset->created_time	= (int)		$rulesetXML[ 'created' ];
		$ruleset->imported_time	= time();
		$ruleset->save();
		
		if ( $rulesetXML->rules->rule )
		{
			foreach ( $rulesetXML->rules->rule as $ruleXML )
			{
				$this->_constructNewRule( $ruleXML, 0, $ruleset->id );
			}
		}
		
		return $ruleset;
	}

	/**
	 * Create A Rule From XML
	 */
	protected function _constructNewRule( $ruleXML, $parent_id, $ruleset_id )
	{
		$rule = new \IPS\rules\Rule;
		
		$rule->parent_id	= (int) 	$parent_id;
		$rule->ruleset_id	= (int)		$ruleset_id;
		$rule->title 		= (string) 	$ruleXML[ 'title' ];
		$rule->weight 		= (int) 	$ruleXML[ 'weight' ];
		$rule->event_app 	= (string) 	$ruleXML[ 'app' ];
		$rule->event_class 	= (string) 	$ruleXML[ 'class' ];
		$rule->event_key	= (string) 	$ruleXML[ 'key' ];
		$rule->base_compare	= (string) 	$ruleXML[ 'compare' ];
		$rule->enabled		= (int) 	$ruleXML[ 'enabled' ];
		$rule->debug		= (int) 	$ruleXML[ 'debug' ];
		$rule->imported_time	= time();
		$rule->save();
		
		if ( $ruleXML->conditions->condition )
		{
			foreach ( $ruleXML->conditions->condition as $conditionXML )
			{
				$this->_constructNewCondition( $conditionXML, 0, $rule->id );
			}
		}
		
		if ( $ruleXML->actions->action )
		{
			foreach ( $ruleXML->actions->action as $actionXML )
			{
				$this->_constructNewAction( $actionXML, $rule->id );
			}
		}
		
		if ( $ruleXML->rules->rule )
		{
			foreach ( $ruleXML->rules->rule as $_ruleXML )
			{
				$this->_constructNewRule( $_ruleXML, $rule->id, $ruleset_id );
			}
		}
		
		return $rule;
	}
	
	/**
	 * Create A Condition From XML
	 */
	protected function _constructNewCondition( $conditionXML, $parent_id, $rule_id )
	{
		$condition = new \IPS\rules\Condition;
		
		$condition->rule_id 		= $rule_id;
		$condition->parent_id		= $parent_id;
		$condition->title 		= (string) 	$conditionXML[ 'title' ];
		$condition->weight 		= (int) 	$conditionXML[ 'weight' ];
		$condition->app			= (string) 	$conditionXML[ 'app' ];
		$condition->class		= (string) 	$conditionXML[ 'class' ];
		$condition->key			= (string) 	$conditionXML[ 'key' ];
		$condition->enabled		= (int) 	$conditionXML[ 'enabled' ];
		$condition->group_compare 	= (string) 	$conditionXML[ 'compare' ];
		$condition->not			= (string) 	$conditionXML[ 'not' ];
		$condition->data 		= json_decode( (string) $conditionXML->data );
		$condition->save();
		
		if ( $conditionXML->conditions->condition )
		{
			foreach ( $conditionXML->conditions->condition as $_conditionXML )
			{
				$this->_constructNewCondition( $_conditionXML, $condition->id, $rule_id );
			}
		}
		
		return $condition;
	}

	/**
	 * Create An Action From XML
	 */
	protected function _constructNewAction( $actionXML, $rule_id )
	{
		$action = new \IPS\rules\Action;
		
		$action->rule_id 		= $rule_id;
		$action->title 			= (string) 	$actionXML[ 'title' ];
		$action->weight 		= (int) 	$actionXML[ 'weight' ];
		$action->app			= (string) 	$actionXML[ 'app' ];
		$action->class			= (string) 	$actionXML[ 'class' ];
		$action->key			= (string) 	$actionXML[ 'key' ];
		$action->enabled		= (int) 	$actionXML[ 'enabled' ];
		$action->schedule_mode		= (int)		$actionXML[ 'schedule_mode' ];
		$action->schedule_minutes 	= (int)		$actionXML[ 'schedule_minutes' ];
		$action->schedule_hours 	= (int)		$actionXML[ 'schedule_hours' ];
		$action->schedule_days 		= (int)		$actionXML[ 'schedule_days' ];
		$action->schedule_months 	= (int)		$actionXML[ 'schedule_months' ];
		$action->schedule_date		= (int)		$actionXML[ 'schedule_date' ];
		$action->schedule_customcode	= (string)	$actionXML->schedule_customcode;
		$action->data 			= json_decode( (string) $actionXML->data );
		$action->save();
		
		return $action;
	}
	
	/**
	 * Create A Custom Action From XML
	 */
	protected function _constructNewCustomAction( $actionXML )
	{
		/**
		 * Delete previous version of this custom action if it exists
		 */
		try
		{
			$custom_action = \IPS\rules\Action\Custom::load( (string) $actionXML[ 'key' ], 'custom_action_key' );
			$custom_action->delete();
		}
		catch ( \OutOfRangeException $e ) {}
		
		$action = new \IPS\rules\Action\Custom;
		
		$action->title 			= (string) 	$actionXML[ 'title' ];
		$action->weight 		= (int) 	$actionXML[ 'weight' ];
		$action->description		= (string) 	$actionXML[ 'description' ];
		$action->key			= (string)	$actionXML[ 'key' ];
		$action->save();
		
		if ( $actionXML->arguments->argument )
		{
			foreach ( $actionXML->arguments->argument as $argumentXML )
			{
				$this->_constructNewArgument( $argumentXML, $action->id );
			}
		}
		
		return $action;
	}	

	/**
	 * Create An Argument From XML
	 */
	protected function _constructNewArgument( $argumentXML, $parent_id )
	{
		$argument = new \IPS\rules\Action\Argument;
		
		$argument->custom_action_id	= $parent_id;
		$argument->name 		= (string) 	$argumentXML[ 'name' ];
		$argument->type 		= (string) 	$argumentXML[ 'type' ];
		$argument->class		= (string) 	$argumentXML[ 'class' ];
		$argument->required		= (int) 	$argumentXML[ 'required' ];
		$argument->weight		= (int)		$argumentXML[ 'weight' ];
		$argument->custom_class		= (string)	$argumentXML[ 'custom_class' ];
		$argument->description		= (string)	$argumentXML[ 'description' ];
		$argument->varname		= (string)	$argumentXML[ 'varname' ];
		$argument->save();
	}
	
}