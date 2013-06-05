<?php
/**
 * @package     Windwalker.Framework
 * @subpackage  Component
 *
 * @copyright   Copyright (C) 2012 Asikart. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * @author      Generated by AKHelper - http://asikart.com
 */

// no direct access
defined('_JEXEC') or die;

jimport('joomla.application.component.controller');

if( JRequest::getVar('ajax') ) {
	ini_set('display_errors', 0);
}

/**
 * Base class for a Joomla Administrator Controller
 *
 * Controller (controllers are where you put all the actual code) Provides basic
 * functionality, such as rendering views (aka displaying templates).
 *
 * @package     Windwalker.Framework
 * @subpackage  Component
 */
class AKController extends JControllerLegacy
{
	/**
	 * elFinderDisplay
	 */
	public function elFinderDisplay()
	{
		//JRequest::setVar('tmpl', 'component', 'method', true) ;
		AKHelper::_('elfinder.display', JRequest::getVar('option') );
	}
	
	/**
	 * elFinderConnector
	 */
	public function elFinderConnector()
	{
		AKHelper::_('elfinder.connector', JRequest::getVar('option') );
		jexit();
	}
	
    /**
	 * quickAddAjax
	 */
	public function quickAddAjax()
	{
		$input = JFactory::getApplication()->input ;
		
		$data  	= $input->post->get( $input->get('formctrl') , array(), 'array');
		$result = new JRegistry();
		$result->set('Result', false);
		
        $model_name = $input->get('model_name') ;
        $component  = $input->get('component') ;
        $extension  = $input->get('extension') ;
        
        JControllerLegacy::addModelPath( JPATH_BASE."/components/com_{$component}/models" );
        JForm::addFormPath( JPATH_BASE."/components/com_{$component}/models/forms" );
        JForm::addFieldPath( JPATH_BASE."/components/com_{$component}/models/fields" );
        JTable::addIncludePath( JPATH_BASE."/components/com_{$component}/tables" );
        AKHelper::_('lang.loadLanguage', $extension, null);
        
		$model 	= $this->getModel( ucfirst( $model_name ), ucfirst($component).'Model' , array('ignore_request' => true) );
        
		$form 	= $model->getForm($data, false);
        
        if( is_callable( array( $model, 'getFieldsName' ) ) ){
            $fields_name = $model->getFieldsName();
            $data 	= AKHelper::_('array.pivotToTwoDimension', $data, $fields_name);
        }
        
		if (!$form)
        {
            $result->set('errorMsg', $model->getError() );
            jexit($result);
        }
		
		// Test whether the data is valid.
        $validData = $model->validate($form, $data);
		
		// Check for validation errors.
        if ($validData === false)
        {
            // Get the validation messages.
            $errors 	= $model->getErrors();
			
			$errorMsg   = is_string($errors[0]) ? $errors[0] : $errors[0]->getMessage() ;
			
			$result->set('errorMsg', $errorMsg );
            jexit($result);
        }
		
		if (!$model->save($validData))
        {
            // Redirect back to the edit screen.
			$result->set('errorMsg', JText::sprintf('JLIB_APPLICATION_ERROR_SAVE_FAILED', $model->getError()) );
            jexit($result);
        }
		
		$data['id'] = $model->getstate($model_name.'.id');
		
		$result->set('Result', true);
		$result->set('data', $data);
		jexit($result);
	}
}