<?php
/**
 * Chaining survey
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2018 Denis Chenu <http://www.sondages.pro>
 * @license GPL v3
 * @version 0.9.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */
class surveyChaining extends PluginBase {

    protected $storage = 'DbStorage';
    static protected $description = 'Chaining surveys';
    static protected $name = 'surveyChaining';

    public function init() {
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');

        $this->subscribe('beforeToolsMenuRender');

        $this->subscribe('afterSurveyComplete');

        Yii::setPathOfAlias('surveyChaining', dirname(__FILE__));

    }

    public function beforeSurveySettings()
    {

    }
    
    public function newSurveySettings()
    {

    }
    /** Menu and settings part */
    /**
     * see beforeToolsMenuRender event
     *
     * @return void
     */

    public function beforeToolsMenuRender()
    {
        $event = $this->getEvent();
        $surveyId = $event->get('surveyId');
        $aMenuItem = array(
            'label' => $this->gT('Survey chaining'),
            'iconClass' => 'fa fa-recycle',
            'href' => Yii::app()->createUrl(
                'admin/pluginhelper',
                array(
                    'sa' => 'sidebody',
                    'plugin' => get_class($this),
                    'method' => 'actionSettings',
                    'surveyId' => $surveyId
                )
            ),
        );
        if (class_exists("\LimeSurvey\Menu\MenuItem")) {
            $menuItem = new \LimeSurvey\Menu\MenuItem($aMenuItem);
        } else {
            $menuItem = new \ls\menu\MenuItem($aMenuItem);
        }
        $event->append('menuItems', array($menuItem));
    }
    /**
     * Main function
     * @param int $surveyId Survey id
     *
     * @return string
     */
    public function actionSettings($surveyId)
    {
        $oSurvey=Survey::model()->findByPk($surveyId);
        if(!$oSurvey) {
            throw new CHttpException(404,$this->translate("This survey does not seem to exist."));
        }
        if(!Permission::model()->hasSurveyPermission($surveyId,'surveysettings','update')){
            throw new CHttpException(401);
        }

        if(App()->getRequest()->getPost('save'.get_class($this))) {
            PluginSetting::model()->deleteAll("plugin_id = :pluginid AND model = :model AND model_id = :sid",array(":pluginid"=>$this->id,":model"=>'Survey',':sid'=>$surveyId));
            $this->set('nextSurvey', App()->getRequest()->getPost('nextSurvey'), 'Survey', $surveyId);
            $this->set('nextEmail', App()->getRequest()->getPost('nextEmail'), 'Survey', $surveyId);
            $this->set('nextMessage', App()->getRequest()->getPost('nextMessage'), 'Survey', $surveyId);

            /* Don't update old choice only of choice are updated */
            $oldChoice = $this->get('choiceQuestion', 'Survey', $surveyId,null);
            $this->set('choiceQuestion', App()->getRequest()->getPost('choiceQuestion'), 'Survey', $surveyId);
            if($this->get('choiceQuestion', 'Survey', $surveyId,null)) {
                $title = $this->get('choiceQuestion', 'Survey', $surveyId,null);
                $oQuestion = Question::model()->find("title=:title and language=:language",array(":title"=>$title,":language"=>$oSurvey->language));
                $aoAnswers = Answer::model()->findAll(array(
                    'condition' => "qid=:qid and language=:language",
                    'order' => 'sortorder ASC',
                    'params' => array(":qid"=>$oQuestion->qid,":language"=>$oSurvey->language)
                ));
                foreach($aoAnswers as $oAnswers) {
                    $code = $oAnswers->code;
                    $this->set('nextSurvey_'.$code, App()->getRequest()->getPost('nextSurvey_'.$code), 'Survey', $surveyId);
                    $this->set('nextEmail_'.$code, App()->getRequest()->getPost('nextEmail_'.$code), 'Survey', $surveyId);
                    $this->set('nextMessage_'.$code, App()->getRequest()->getPost('nextMessage_'.$code), 'Survey', $surveyId);
                }
            }
            if(App()->getRequest()->getPost('save'.get_class($this)=='redirect')) {
                Yii::app()->getController()->redirect(Yii::app()->getController()->createUrl('admin/survey',array('sa'=>'view','surveyid'=>$surveyId)));
            }
        }

        $aData=array();
        $aData['warningString'] = null;
        $aSettings=array();
        /* Basic settings */
        //$aWholeSurveys = Survey::model()->with('permission')
        $aWholeSurveys = Survey::model()
            ->permission(Yii::app()->user->getId())
            ->with('defaultlanguage')
            ->findAll(array('order'=>'surveyls_title'));
        $sHelpSurveyTable = null;
        $iNextSurvey = $this->get('nextSurvey', 'Survey', $surveyId,null);
        
        if($iNextSurvey) {
            $oNextSurvey = Survey::model()->findByPk($iNextSurvey);
            if(!$oNextSurvey) {
                $sHelpSurveyTable = CHtml::tag("div",array('class'=>"text-warning"),$this->gT("Warning : previous survey selected don't exist currently."));
            }
            if($oNextSurvey) {
                if(!$oNextSurvey->getHasTokensTable()) {
                    $sHelpSurveyTable = CHtml::tag("div",array('class'=>"text-danger"),$this->gT("Warning : current survey selected don't have token table, you must enable token before."));
                }
                if($oNextSurvey->getHasTokensTable() && $oNextSurvey->tokenanswerspersistence!="Y") {
                    $sHelpSurveyTable = CHtml::tag("div",array('class'=>"text-danger"),$this->gT("Warning : current survey selected don't have token answer persistance enable."));
                }
            }
        }
        $aNextSettings = array(
            'nextSurvey' => array(
                'type'=>'select',
                'htmlOptions'=>array(
                    'empty'=>$this->gT("None"),
                ),
                'label'=>$this->gT("Next survey (by default)."),
                'options'=>CHtml::listData($aWholeSurveys,'sid','defaultlanguage.surveyls_title'),
                'current'=>$this->get('nextSurvey', 'Survey', $surveyId,null),
                'help' => $sHelpSurveyTable,
            ),
            'nextEmail' => array(
                'type' => 'string',
                'label' => $this->gT("Send email to"),
                'help' => $this->gT("You can use Expression Manager with question code"),
                'current'=>$this->get('nextEmail', 'Survey', $surveyId,null),

            ),
            'nextMessage' => array(
                'type' => 'select',
                'label' => $this->gT("Mail template to use"),
                'htmlOptions'=>array(
                    'empty'=>$this->gT("Invitation (Default)"),
                ),
                'options'=>array(
                    "invite" => $this->gT("Invitation"),
                    "remind" => $this->gT("Reminder"),
                    "register" => $this->gT("Register"),
                    "admin_notification" => $this->gT("Admin notification"),
                    "admin_responses" => $this->gT("Admin detailed response"),
                ),
                'current'=>$this->get('nextMessage', 'Survey', $surveyId,null),
            ),
        );
        $aSettings[$this->gT("Next survey")] = $aNextSettings;
        $oQuestionCriteria = new CDbCriteria();
        $oQuestionCriteria->condition = "t.sid =:sid and t.language=:language and parent_qid = 0";
        $oQuestionCriteria->params = array(":sid"=>$surveyId,":language"=>$oSurvey->language);
        $oQuestionCriteria->order = "group_order ASC, question_order ASC";
        $oQuestionCriteria->addInCondition("type",['L','O','!']);
        $oaQuestion = Question::model()->with('groups')->findAll($oQuestionCriteria);

        $aNextQuestionSettings = array(
            'choiceQuestion' => array(
                'type'=>'select',
                'htmlOptions'=>array(
                    'empty'=>$this->gT("None"),
                ),
                'label'=>$this->gT("Question for next surveys."),
                'options'=>CHtml::listData($oaQuestion,'title',
                    function($oQuestion) {
                        return "[".$oQuestion->title."] ".viewHelper::flatEllipsizeText($oQuestion->question,1,40,"…");
                    }
                ),
                'current'=>$this->get('choiceQuestion', 'Survey', $surveyId,null),
                'help' => $this->gT("Only single choice question type can be used for survey selection. The list of available answer update after save this value."),
            ),
        );
        $aSettings[$this->gT("Question for conditionnal surveys")] = $aNextQuestionSettings;

        /* Text for default */
        $sDefaultText = $this->gT("None");
        if(!empty($oNextSurvey)) {
            $sDefaultText = $this->gT("Current default");
        }
        if($this->get('choiceQuestion', 'Survey', $surveyId,null)) {
            $title = $this->get('choiceQuestion', 'Survey', $surveyId,null);
            $oQuestion = Question::model()->find("title=:title and language=:language",array(":title"=>$title,":language"=>$oSurvey->language));
            $aoAnswers = Answer::model()->findAll(array(
                'condition' => "qid=:qid and language=:language",
                'order' => 'sortorder ASC',
                'params' => array(":qid"=>$oQuestion->qid,":language"=>$oSurvey->language)
            ));
            foreach($aoAnswers as $oAnswers) {
                $code = $oAnswers->code;
                $aNextSettings = array(
                    'nextSurvey_'.$code => array(
                        'type'=>'select',
                        'htmlOptions'=>array(
                            'empty'=>$sDefaultText,
                        ),
                        'label'=>$this->gT("Next survey."),
                        'options'=>CHtml::listData($aWholeSurveys,'sid','defaultlanguage.surveyls_title'),
                        'current'=>$this->get('nextSurvey_'.$code, 'Survey', $surveyId,null),
                    ),
                    'nextEmail_'.$code => array(
                        'type' => 'string',
                        'label' => $this->gT("Send email to"),
                        'help' => $this->gT("You can use Expression Manager with question code"),
                        'current'=>$this->get('nextEmail_'.$code, 'Survey', $surveyId,null),

                    ),
                    'nextMessage_'.$code => array(
                        'type' => 'select',
                        'label' => $this->gT("Mail template to use"),
                        'htmlOptions'=>array(
                            'empty'=>$this->gT("Invitation (Default)"),
                        ),
                        'options'=>array(
                            "invite" => $this->gT("Invitation"),
                            "remind" => $this->gT("Reminder"),
                            "register" => $this->gT("Register"),
                            "admin_notification" => $this->gT("Admin notification"),
                            "admin_responses" => $this->gT("Admin detailed response"),
                        ),
                        'current'=>$this->get('nextMessage_'.$code, 'Survey', $surveyId,null),
                    ),
                );
                $aSettings[sprintf($this->gT("Conditionnal survey (%s)"),$code)] = $aNextSettings;
            }

        }

        $aData['pluginClass']=get_class($this);
        $aData['surveyId']=$surveyId;
        $aData['title']=$this->gT("Survey chaining settings");
        $aData['aSettings']=$aSettings;
        $aData['assetUrl']=Yii::app()->assetManager->publish(dirname(__FILE__) . '/assets/');
        $aSettings=array();
        $content = $this->renderPartial('settings', $aData, true);

        return $content;
    }

    /**
     * Action to do when survey is completed
     */
    public function afterSurveyComplete()
    {
        $nextSurvey = $oNextSurvey = null;
        $surveyId = $this->getEvent()->get('surveyId');
        $responseId = $this->getEvent()->get('responseId');
        $choiceQuestion = $this->get('choiceQuestion', 'Survey', $surveyId,null);
        $nextEmailSetting = 'nextEmail';
        $nextMessageSetting = 'nextMessage';

        $currentResponse = $this->pluginManager->getAPI()->getResponse($surveyId, $responseId);
        $currentChoice = null;
        if($choiceQuestion) {
            // Find current value of choiceQuestion -if exist)
            if(!empty($currentResponse[$choiceQuestion])) {
                $currentChoice = strval($currentResponse[$choiceQuestion]);
                $nextSurvey = $this->get('nextSurvey_'.$currentChoice, 'Survey', $surveyId,null);
                if($nextSurvey) {
                    $nextEmailSetting = 'nextEmail_'.$currentChoice;
                    $nextMessageSetting = 'nextMessage_'.$currentChoice;
                }
            }
        }
        if(!$nextSurvey) {
            $nextSurvey = $this->get('nextSurvey', 'Survey', $surveyId,null);
        }
        if(!$nextSurvey) {
            return;
        }
        Yii::log($this->gT("Survey selected for $surveyId : $nextSurvey"),\CLogger::LEVEL_TRACE,'plugin.'.get_class($this).".afterSurveyComplete");
        $oNextSurvey = Survey::model()->findByPk($nextSurvey);
        if(!$oNextSurvey) {
            Yii::log($this->gT("Invalid survey selected for $surveyId (didn{t exist)"),\CLogger::LEVEL_WARNING,'plugin.'.get_class($this).".afterSurveyComplete");
            return;
        }
        if(!$oNextSurvey->getHasTokensTable()) {
            Yii::log($this->gT("Invalid survey selected for $surveyId (No token table)"),\CLogger::LEVEL_WARNING,'plugin.'.get_class($this).".afterSurveyComplete");
            return;
        }
        /* Ok we get here : do action */
        //~ $currentColumnsToCode = \surveyChaining\surveyCodeHelper::getColumnsToCode($surveyId);
        $nextCodeToColumn =  array_flip(\surveyChaining\surveyCodeHelper::getColumnsToCode($nextSurvey));
        $nextExistingCodeToColumn = array_intersect_key($nextCodeToColumn,$currentResponse);
        if(empty($nextExistingCodeToColumn)) {
            Yii::log($this->gT("No question code corresponding for $surveyId"),\CLogger::LEVEL_WARNING,'plugin.'.get_class($this).".afterSurveyComplete");
            return;
        }
        /* @TODO : usage without token enable survey */
        /* Create the token */
        $oToken = Token::create($nextSurvey);
        $oToken->validfrom = date("Y-m-d H:i:s");
        $oToken->email = trim(LimeExpressionManager::ProcessStepString($this->get($nextEmailSetting, 'Survey', $surveyId,""),array(),3,null));
        $oToken->generateToken();
        $language = App()->getLanguage();
        if(!in_array($language,$oNextSurvey->getAllLanguages())) {
            $language = $oNextSurvey->language;
        }
        $oToken->language;
        /* @todo : set attribute */
        if(!$oToken->save()) {
            Yii::log($this->gT("Unable to create token for $nextSurvey"),\CLogger::LEVEL_ERROR,'plugin.'.get_class($this).".afterSurveyComplete");
            return;
        }
        
        $token = $oToken->token;
        $oResponse = Response::create($nextSurvey);
        foreach($nextExistingCodeToColumn as $code=>$column) {
            $oResponse->$column = $currentResponse[$code];
        }
        $oResponse->startlanguage = $language;
        $oResponse->token = $token;
        if(!$oResponse->save()) {
            Yii::log($this->gT("Unable to save response for token $token for $nextSurvey"),\CLogger::LEVEL_ERROR,'plugin.'.get_class($this).".afterSurveyComplete");
            return;
        }
        /* Get email and send */
        $nextMessage = $this->get($nextMessageSetting, 'Survey', $surveyId, "invite");
        if($currentChoice && $nextMessage==='') {
            $nextMessage = $this->get('nextMessage', 'Survey', $surveyId, "invite");
        }
        if($nextMessage==='') {
            $nextMessage = 'invite';
        }

        if($oToken->email && $nextMessage) {
            $this->_sendSurveyChainingTokenEmail($nextSurvey,$oToken,$nextMessage);
        }
    }

    /**
     * send email with SURVEYURL to new survey
     * @param integer $iSurvey
     * @param Object $oToken \Token
     * @param integer $responseId
     * @param string $mailType
     * @return boolean
     */
    private function _sendSurveyChainingTokenEmail($nextSurvey,$oToken,$mailType = 'invite') {
        global $maildebug;
        if(!in_array($mailType,array('invite','remind','register','confirm','admin_notification','admin_responses')) ) {
            if(defined('YII_DEBUG') && YII_DEBUG) {
                throw new Exception("Invalid mail type set ({$mailType}).");
            }
            return false;
        }
        $oSurvey = Survey::model()->findByPk($nextSurvey);
        $sLanguage = $oToken->language;
        if(!in_array($sLanguage,$oSurvey->getAllLanguages())) {
            $sLanguage = $oSurvey->language;
        }
        $oSurveyLanguage = SurveyLanguageSetting::model()->findByPk(array('surveyls_survey_id'=>$nextSurvey,'surveyls_language'=>$sLanguage));

        $attSubject = 'surveyls_email_'.$mailType.'_subj';
        $attMessage = 'surveyls_email_'.$mailType;
        $sSubject = $oSurveyLanguage->$attSubject;
        $sMessage = $oSurveyLanguage->$attMessage;
        $aReplacementFields=array();
        $aReplacementFields["ADMINNAME"]=$oSurvey->admin;
        $aReplacementFields["ADMINEMAIL"]=$oSurvey->adminemail;
        $aReplacementFields["SURVEYNAME"]=$oSurveyLanguage->surveyls_title;
        $aReplacementFields["SURVEYDESCRIPTION"]=$oSurveyLanguage->surveyls_description;
        $aReplacementFields["EXPIRY"]=$oSurvey->expires;
        foreach($oToken->attributes as $attribute=>$value){
            $aReplacementFields[strtoupper($attribute)]=$value;
        }
        $sToken=$oToken->token;
        $useHtmlEmail = ($oSurvey->htmlemail == 'Y');
        
        //~ $sSubject=preg_replace("/{TOKEN:([A-Z0-9_]+)}/","{"."$1"."}",$sSubject);
        //~ $sMessage=preg_replace("/{TOKEN:([A-Z0-9_]+)}/","{"."$1"."}",$sMessage);
        $aReplacementFields["SURVEYURL"] = Yii::app()->getController()->createAbsoluteUrl("/survey/index/sid/{$nextSurvey}",array('lang'=>$sLanguage,'token'=>$sToken));
        $aReplacementFields["OPTOUTURL"] = Yii::app()->getController()->createAbsoluteUrl("/optout/tokens/surveyid/{$nextSurvey}",array('langcode'=>$sLanguage,'token'=>$sToken));
        $aReplacementFields["OPTINURL"] = Yii::app()->getController()->createAbsoluteUrl("/optin/tokens/surveyid/{$nextSurvey}",array('langcode'=>$sLanguage,'token'=>$sToken));
        foreach(array('OPTOUT', 'OPTIN', 'SURVEY') as $key) {
            $url = $aReplacementFields["{$key}URL"];
            if ($useHtmlEmail) {
                $aReplacementFields["{$key}URL"] = "<a href='{$url}'>" . htmlspecialchars($url) . '</a>';
            }
            $sSubject = str_replace("@@{$key}URL@@", $url, $sSubject);
            $sMessage = str_replace("@@{$key}URL@@", $url, $sMessage);
        }
        $sSubject = LimeExpressionManager::ProcessStepString($sSubject,$aReplacementFields,3,1);
        $sMessage = LimeExpressionManager::ProcessStepString($sMessage,$aReplacementFields,3,1);
        $mailFromName = $oSurvey->admin;
        $mailFromMail = empty($oSurvey->adminemail) ? App()->getConfig('siteadminemail') : $oSurvey->adminemail;
        $sFrom = !empty($mailFromName) ? "{$mailFromName} <{$mailFromMail}>" : $mailFromMail;
        $sBounce=$oSurvey->bounce_email;
        /* Get array (EM replaced) of to */
        $aEmailTo = array();
        $sSendTo = $oToken->email;
        $aRecipient = explode(";", LimeExpressionManager::ProcessStepString($sSendTo,$aReplacementFields,3,1));
        foreach ($aRecipient as $sRecipient) {
            $sRecipient = trim($sRecipient);
            if (validateEmailAddress($sRecipient)) {
                $aEmailTo[] = $sRecipient;
            }
        }
        $sended = false;
        if(!empty($aEmailTo)) {
            foreach($aEmailTo as $sEmail) {
                if(SendEmailMessage($sMessage, $sSubject, $sEmail, $sFrom, App()->getConfig("sitename"), $useHtmlEmail, $sBounce)) {
                    $sended = true;
                }
            }
        } else {
            Yii::log($this->gT("Unable to send email with debug : {$maildebug}"),\CLogger::LEVEL_ERROR,'plugin.'.get_class($this).".afterSurveyComplete._sendSurveyChainingTokenEmail");
        }
        if($sended) {
            $oToken->sent = dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i", App()->getConfig("timeadjust"));
            $oToken->save();
            return true;
        }
        return false;
    }
}
