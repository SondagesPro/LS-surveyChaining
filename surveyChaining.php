<?php
/**
 * Chaining survey
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2018-2020 Denis Chenu <http://www.sondages.pro>
 * @copyright 2018 DRAAF Bourgogne-Franche-Comte <http://draaf.bourgogne-franche-comte.agriculture.gouv.fr/>
 * @license GPL v3
 * @version 1.0.0
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

    /**
     * @var integer dbversion
     */
    var $dbversion = 1;

    /**
     * @var boolean did this done (see issue in limesurvey)
     */
    var $done = false;

    public function init() {
        /* Config must be set before all other */
        $oPlugin = Plugin::model()->find("name = :name",array("name"=>get_class($this)));
        if($oPlugin && $oPlugin->active) {
          $this->_setConfig();
        }
        /* Add menu in tool menu */
        $this->subscribe('beforeToolsMenuRender');
        /* Add menu in tool menu */
        $this->subscribe('afterSurveyComplete');
        /* */
        $this->subscribe('beforeControllerAction');
        /* */
        $this->subscribe('newDirectRequest');
        /* when survey is deleted : must delete all related links */
        $this->subscribe('afterSurveyDelete');
    }

    /** @inheritdoc **/
    public function beforeControllerAction()
    {
      $this->_setDb();
    }

    /** */
    public function newDirectRequest()
    {
        if($this->getEvent()->get('target') != get_class($this)) {
            return;
        }
        if(!Permission::model()->getUserId() ) {
            throw new CHttpException(401);
        }
        
        $surveyId = App()->getRequest()->getParam('sid');
        $destSurveyId = App()->getRequest()->getParam('destsid');
        if(!$surveyId || !$destSurveyId) {
            throw new CHttpException(500,$this->_translate("This action need a survey and a destination survey id"));
        }
        $oAnswersAsReadonly = Plugin::model()->find("name = :name",array(":name"=>'answersAsReadonly'));
        if (!$oAnswersAsReadonly || !$oAnswersAsReadonly->active) {
            $this->_renderJson(array('error'=>array('message'=>$this->_translate("answersAsReadonly plugin didn't exist or is not activated."))));
        }
        if(!Permission::model()->hasSurveyPermission($surveyId,'surveysettings','update')){
            throw new CHttpException(403);
        }
        if(!Permission::model()->hasSurveyPermission($destSurveyId,'surveysettings','update')){
            throw new CHttpException(403,sprintf($this->_translate("You don't have permission on survey %s"),$destSurveyId));
        }
        //~ $oSurvey = Survey::model()->findByPk($surveyId);
        //~ $oDestSurvey = Survey::model()->findByPk($destSurveyId);
        $aSameCode = $this->_getSameCodes($surveyId,$destSurveyId);
        if(empty($aSameCode)) {
            $this->_renderJson(array('error'=>array('message'=>$this->_translate("Survey selected and current survey didn't have any correspondig question."))));
        }
        $aQidColumnsToCode = \getQuestionInformation\helpers\surveyCodeHelper::getAllQuestions($destSurveyId,true);
        $aQidToDo = array_filter($aQidColumnsToCode, function($aColumnToCode) use ($aSameCode) {
            return count(array_intersect($aSameCode,$aColumnToCode));
        });
        foreach(array_keys($aQidToDo) as $qid) {
            QuestionAttribute::model()->setQuestionAttribute($qid,'readonly',1);
        }
        $this->_renderJson(array(
            'success'=>sprintf($this->_translate("Question(s) %s are set to readonly"),implode(',',array_keys($aQidToDo)))
        ));
    }
    /** @inheritdoc **/
    public function afterSurveyDelete()
    {
        /* Delete all link when set a survey is deleted */
        $oSurvey = $this->getEvent()->get('model');
            if($oSurvey->sid) {
                $deleted = \surveyChaining\models\chainingResponseLink::model()->deleteAll("prevsid = :prevsid OR nextsid =:nextsid",array(':prevsid'=>$oSurvey->sid,':nextsid'=>$oSurvey->sid));
                if($deleted>0) { // Don't log each time, can be saved for something other …
                $this->log(sprintf("%d chainingResponseLink deleted for %d",$deleted,$oSurvey->sid),CLogger::LEVEL_INFO);
            }
        }
    }

    /** @inheritdoc **/
    public function beforeToolsMenuRender()
    {
        $event = $this->getEvent();
        $surveyId = $event->get('surveyId');
        $aMenuItem = array(
            'label' => $this->_translate('Survey chaining'),
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
            throw new CHttpException(403);
        }

        if(App()->getRequest()->getPost('save'.get_class($this))) {
            PluginSetting::model()->deleteAll("plugin_id = :pluginid AND model = :model AND model_id = :sid",array(":pluginid"=>$this->id,":model"=>'Survey',':sid'=>$surveyId));
            $this->set('nextSurvey', App()->getRequest()->getPost('nextSurvey'), 'Survey', $surveyId);
            $this->set('findExistingLink', App()->getRequest()->getPost('findExistingLink'), 'Survey', $surveyId);
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
                    $this->set('findExistingLink_'.$code, App()->getRequest()->getPost('findExistingLink_'.$code), 'Survey', $surveyId);
                    $this->set('nextEmail_'.$code, App()->getRequest()->getPost('nextEmail_'.$code), 'Survey', $surveyId);
                    $this->set('nextMessage_'.$code, App()->getRequest()->getPost('nextMessage_'.$code), 'Survey', $surveyId);
                }
            }
            if(App()->getRequest()->getPost('save'.get_class($this))=='redirect') {
                Yii::app()->getController()->redirect(Yii::app()->getController()->createUrl('admin/survey',array('sa'=>'view','surveyid'=>$surveyId)));
            }
        }

        $aData=array();
        $aData['warningString'] = null;
        if(!Yii::getPathOfAlias('getQuestionInformation')) {
            $aData['warningString'] = sprintf($this->_translate("You must add an activate %s for this plugin."),"<a href='https://gitlab.com/SondagesPro/coreAndTools/getQuestionInformation'>getQuestionInformation</a>");
        }
        $aSettings=array();
        /* Basic settings */
        //$aWholeSurveys = Survey::model()->with('permission')
        $aWholeSurveys = Survey::model()
            ->permission(Yii::app()->user->getId())
            ->with('defaultlanguage')
            ->findAll(array('order'=>'surveyls_title'));
        $sHelpSurveyTable = null;
        $iNextSurvey = $this->get('nextSurvey', 'Survey', $surveyId,null);

        $aNextSettings = array(
            'nextSurvey' => array(
                'type'=>'select',
                'htmlOptions'=>array(
                    'empty'=>$this->_translate("None"),
                ),
                'label'=>$this->_translate("Next survey (by default)."),
                'options'=>CHtml::listData($aWholeSurveys,'sid','defaultlanguage.surveyls_title'),
                'current'=>$this->get('nextSurvey', 'Survey', $surveyId,null),
                'help' => $this->_getHelpFoSurveySetting($surveyId,$this->get('nextSurvey', 'Survey', $surveyId,null)),
            ),
            'findExistingLink' => array(
                'type' => 'boolean',
                'label' => $this->_translate("Update existing response if exist."),
                'help' => $this->_translate("If you check this settings and a chain already exist with this respone, previous response was updated, else a new empty response was updated. If you want to keep history : disable this setting."),
                'current'=>$this->get('findExistingLink', 'Survey', $surveyId,1),
                'current'=>$this->get('findExistingLink', 'Survey', $surveyId,1),
            ),
            'nextEmail' => array(
                'type' => 'string',
                'label' => $this->_translate("Send email to"),
                'help' => $this->_translate("You can use Expression Manager with question code"),
                'current'=>$this->get('nextEmail', 'Survey', $surveyId,null),

            ),
            'nextMessage' => array(
                'type' => 'select',
                'label' => $this->_translate("Mail template to use"),
                'htmlOptions'=>array(
                    'empty'=>$this->_translate("Invitation (Default)"),
                ),
                'options'=>array(
                    "invite" => $this->_translate("Invitation"),
                    "remind" => $this->_translate("Reminder"),
                    "register" => $this->_translate("Register"),
                    "admin_notification" => $this->_translate("Admin notification"),
                    "admin_responses" => $this->_translate("Admin detailed response"),
                ),
                'current'=>$this->get('nextMessage', 'Survey', $surveyId,null),
            ),
        );
        $aSettings[$this->_translate("Next survey")] = $aNextSettings;
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
                    'empty'=>$this->_translate("None"),
                ),
                'label'=>$this->_translate("Question determining the following survey"),
                'options'=>CHtml::listData($oaQuestion,'title',
                    function($oQuestion) {
                        return "[".$oQuestion->title."] ".viewHelper::flatEllipsizeText($oQuestion->question,1,40,"…");
                    }
                ),
                'current'=>$this->get('choiceQuestion', 'Survey', $surveyId,null),
                'help' => $this->_translate("Only single choice question type can be used for survey selection. The list of available answer update after save this settings."),
            ),
        );
        $aSettings[$this->_translate("Surveys determined by a question inside this survey")] = $aNextQuestionSettings;

        /* Text for default */
        $sDefaultText = $this->_translate("None");
        if(!empty($oNextSurvey)) {
            $sDefaultText = $this->_translate("Current default");
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
                        'type' => 'select',
                        'htmlOptions' => array(
                            'empty' => $sDefaultText,
                        ),
                        'label' => $this->_translate("Next survey according to the choice"),
                        'options' =>CHtml::listData($aWholeSurveys,'sid','defaultlanguage.surveyls_title'),
                        'current'=> intval($this->get('nextSurvey_'.$code, 'Survey', $surveyId,null)),
                        'help' => $this->_getHelpFoSurveySetting($surveyId,$this->get('nextSurvey_'.$code, 'Survey', $surveyId,null)),
                    ),
                    'findExistingLink_'.$code => array(
                        'type' => 'boolean',
                        'label' => $this->_translate("Update existing response if exist."),
                        'current'=>$this->get('findExistingLink_'.$code, 'Survey', $surveyId,1),
                    ),
                    'nextEmail_'.$code => array(
                        'type' => 'string',
                        'label' => $this->_translate("Send email to"),
                        'help' => $this->_translate("You can use Expression Manager with question code"),
                        'current'=>$this->get('nextEmail_'.$code, 'Survey', $surveyId,null),

                    ),
                    'nextMessage_'.$code => array(
                        'type' => 'select',
                        'label' => $this->_translate("Mail template to use"),
                        'htmlOptions'=>array(
                            'empty'=>$this->_translate("Invitation (Default)"),
                        ),
                        'options'=>array(
                            "invite" => $this->_translate("Invitation"),
                            "remind" => $this->_translate("Reminder"),
                            "register" => $this->_translate("Register"),
                            "admin_notification" => $this->_translate("Admin notification"),
                            "admin_responses" => $this->_translate("Admin detailed response"),
                        ),
                        'current'=>$this->get('nextMessage_'.$code, 'Survey', $surveyId,null),
                    ),
                );
                $aSettings[sprintf($this->_translate("Next survey for %s (%s)"),$code,viewHelper::flatEllipsizeText($oAnswers->answer,1,60,"…"))] = $aNextSettings;
            }

        }

        $aData['pluginClass']=get_class($this);
        $aData['surveyId']=$surveyId;
        $aData['title']=$this->_translate("Survey chaining settings");
        $aData['aSettings']=$aSettings;
        $aData['assetUrl']=Yii::app()->assetManager->publish(dirname(__FILE__) . '/assets/');
        if(App()->getConfig("debug")) {
            $aData['assetUrl'] = Yii::app()->request->getBaseUrl()."/plugins/surveyChaining/assets";
        }

        $aSettings=array();
        $content = $this->renderPartial('settings', $aData, true);

        return $content;
    }

    /**
     * Action to do when survey is completed
     */
    public function afterSurveyComplete()
    {
        if(!Yii::getPathOfAlias('getQuestionInformation')) {
            return;
        }
        $nextSurvey = $oNextSurvey = null;
        $surveyId = $this->getEvent()->get('surveyId');
        $oCurrentSurvey = Survey::model()->findByPk($surveyId);
        if(empty($oCurrentSurvey)) {
            return;
        }
        $responseId = $this->getEvent()->get('responseId');
        $choiceQuestion = $this->get('choiceQuestion', 'Survey', $surveyId,null);
        $nextEmailSetting = 'nextEmail';
        $nextMessageSetting = 'nextMessage';
        $existingLinkSetting = 'findExistingLink';

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
                    $existingLinkSetting = 'findExistingLink_'.$currentChoice;
                }
            }
        }
        if(!$nextSurvey) {
            $nextSurvey = $this->get('nextSurvey', 'Survey', $surveyId,null);
        }
        if(!$nextSurvey) {
            return;
        }

        $this->log($this->_translate("Survey selected for $surveyId : $nextSurvey"),\CLogger::LEVEL_INFO);
        $oNextSurvey = Survey::model()->findByPk($nextSurvey);
        if(!$oNextSurvey) {
            $this->log($this->_translate("Invalid survey selected for $surveyId (didn't exist)"),\CLogger::LEVEL_WARNING);
            return;
        }
        if(!$this->_hasTokenTable($oNextSurvey->sid) && !$this->_reloadAnyResponseExist()) {
            $this->log($this->_translate("Invalid survey selected for $surveyId (No token table) and reloadAnyResponse plugin not installed."),\CLogger::LEVEL_WARNING);
            return;
        }
        $sEmail = $this->_EMProcessString($this->get($nextEmailSetting, 'Survey', $surveyId,""));

        /* Ok we get here : do action */
        $nextCodeToColumn =  array_flip(\getQuestionInformation\helpers\surveyCodeHelper::getAllQuestions($nextSurvey));
        $nextExistingCodeToColumn = array_intersect_key($nextCodeToColumn,$currentResponse);
        if(empty($nextExistingCodeToColumn)) {
            $this->log($this->_translate("No question code corresponding for $surveyId"),\CLogger::LEVEL_WARNING);
            return;
        }
        /* Find upload QuestionType */
        $aUploadColumns = array();
        $oUploadQuestionsType = \Question::model()->findAll(array(
            'select' => 'title, sid,gid,qid',
            'condition' => "sid = :sid and language = :language",
            'params' => array(":sid"=>$nextSurvey,":language"=>Yii::app()->getlanguage()),
        ));
        if(!empty($oUploadQuestionsType)) {
            foreach($oUploadQuestionsType as $oQuestion) {
                $sqga = $oQuestion->sid."X".$oQuestion->gid."X".$oQuestion->qid;
                if(in_array($sqga,$nextExistingCodeToColumn)) {
                    $aUploadColumns[$oQuestion->title] = $sqga;
                }
            }
        }
        $nextsrid = null;
        if($this->get($existingLinkSetting, 'Survey', $surveyId,1)) {
            /* Find if previous response */
            $chainingResponseLink = \surveyChaining\models\chainingResponseLink::model()->find(
                "prevsid = :prevsid AND nextsid = :nextsid AND prevsrid = :prevsrid",
                array(':prevsid'=>$surveyId,':nextsid'=>$nextSurvey,':prevsrid'=>$responseId)
            );
            if(!empty($chainingResponseLink)) {
                $nextsrid = $chainingResponseLink->nextsrid;
            }
            /* If don't have : get the inverse */
            if(empty($chainingResponseLink)) {
                $chainingResponseLinkInverse = \surveyChaining\models\chainingResponseLink::model()->find(
                    "prevsid = :prevsid AND nextsid = :nextsid AND nextsrid = :nextsrid",
                    array(':prevsid'=>$nextSurvey,':nextsid'=>$surveyId,':nextsrid'=>$responseId)
                );
                if(!empty($chainingResponseLinkInverse)) {
                    $nextsrid = $chainingResponseLinkInverse->prevsrid;
                }
            }
        }
        $oResponse = null;
        if($nextsrid) {
            $oResponse = Response::model($nextSurvey)->findByPk($nextsrid);
            if(empty($oResponse)) {
                $this->log($this->_translate("A chaining between $surveyId and $nextSurvey but {$chainingResponseLink->nextsrid} not found. We delete all links."),\CLogger::LEVEL_WARNING);
                \surveyChaining\models\chainingResponseLink::model()->deleteAll(
                    "prevsid = :prevsid AND nextsid = :nextsid AND prevsrid = :prevsrid",
                    array(':prevsid'=>$nextSurvey,':nextsid'=>$surveyId,':prevsrid'=>$responseId)
                );
                \surveyChaining\models\chainingResponseLink::model()->deleteAll(
                    "prevsid = :prevsid AND nextsid = :nextsid AND nextsrid = :nextsrid",
                    array(':prevsid'=>$nextSurvey,':nextsid'=>$surveyId,':nextsrid'=>$responseId)
                );
                $chainingResponseLink = null;
            }
        }
        $oToken = null;
        if($this->_hasTokenTable($oNextSurvey->sid) && !$oNextSurvey->getIsAnonymized()) {
            /* find token */
            if($oResponse) {
                $oToken = Token::model($nextSurvey)->find("token = :token",array(':token'=>$oResponse->token));
                $oToken->email = $sEmail;
            }
            /* Else create the token */
            if(!$oToken) {
                $aAttributes = array();
                /* To set attributes by current token if exist */
                if($this->_hasTokenTable($oCurrentSurvey->sid) && !$oCurrentSurvey->getIsAnonymized()) {
                    $oCurrentToken = Token::model($surveyId)->find("token = :token",array(':token'=>$currentResponse['token']));
                    if(!empty($oCurrentToken)) {
                        $aAttributes = $oCurrentToken->attributes;
                        unset($aAttributes['tid']);
                        unset($aAttributes['participant_id']);
                        unset($aAttributes['token']);
                        unset($aAttributes['email']);
                        unset($aAttributes['emailstatus']);
                        unset($aAttributes['blacklisted']);
                        unset($aAttributes['sent']);
                        unset($aAttributes['remindersent']);
                        unset($aAttributes['remindercount']);
                        unset($aAttributes['completed']);
                        unset($aAttributes['usesleft']);
                        unset($aAttributes['validfrom']);
                        unset($aAttributes['validuntil']);
                    }
                }
                $oToken = $this->_createToken($nextSurvey,$sEmail,$aAttributes);
            }
        }
        /* Create response */
        if (!$oResponse) {
            $oResponse = Response::create($nextSurvey);
        }
        foreach($nextExistingCodeToColumn as $code=>$column) {
            $oResponse->$column = $currentResponse[$code];
        }
        $oResponse->startlanguage = App()->getLanguage();
        if($oToken && !$oNextSurvey->getIsAnonymized()) {
            $oResponse->token = $oToken->token;
        }
        if($oNextSurvey->datestamp == "Y") {
            $oResponse->startdate = dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i:s", Yii::app()->getConfig('timeadjust'));
            $oResponse->datestamp = dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i:s", Yii::app()->getConfig('timeadjust'));
        }
        if(!$oResponse->save()) {
            $this->log("Unable to save response for survey {$surveyId}, response {$responseId} for $nextSurvey",\CLogger::LEVEL_ERROR);
            $this->log(CVarDumper::dumpAsString($oResponse->getErrors()),CLogger::LEVEL_ERROR);
            return;
        }
        /* Copy uploaded files */
        foreach($aUploadColumns as $sUploadColumn) {
            $uploaddir = Yii::app()->getConfig("uploaddir");
            if(!empty($oResponse->$sUploadColumn)) {
                $responseJson = $oResponse->$sUploadColumn;
                $aUploadedFiles = @json_decode($responseJson,1);
                if(!empty($aUploadedFiles) && is_array($aUploadedFiles)) {
                    foreach($aUploadedFiles as $aUploadedFile) {
                        if(!empty($aUploadedFile['filename'])) {
                            $file = "{$uploaddir}/surveys/{$surveyId}/files/{$aUploadedFile['filename']}";
                            if(is_file($file)) {
                                if(!is_dir("{$uploaddir}/surveys/{$nextSurvey}")) {
                                    mkdir("{$uploaddir}/surveys/{$nextSurvey}");
                                }
                                if(!is_dir("{$uploaddir}/surveys/{$nextSurvey}/files")) {
                                    mkdir("{$uploaddir}/surveys/{$nextSurvey}/files");
                                }
                                if(!copy($file,"{$uploaddir}/surveys/{$nextSurvey}/files/{$aUploadedFile['filename']}")) {
                                    $this->log("Unable to copy {$aUploadedFile['filename']} from {$surveyId} to {$nextSurvey}",\CLogger::LEVEL_ERROR);
                                }
                            } else {
                                $this->log("Unable to find {$aUploadedFile['filename']} file in survey {$surveyId}",\CLogger::LEVEL_ERROR);
                            }
                        } else {
                                $this->log("Invalid updloaded files in survey {$surveyId}",\CLogger::LEVEL_ERROR);
                                $this->log(CVarDumper::dumpAsString($aUploadedFile),\CLogger::LEVEL_WARNING);
                        }
                    }
                }
            }
        }
        /* save links between responses */
        if(empty($chainingResponseLink)) {
            $chainingResponseLink = new \surveyChaining\models\chainingResponseLink;
            $chainingResponseLink->prevsid = $surveyId;
            $chainingResponseLink->prevsrid = $responseId;
            $chainingResponseLink->nextsid = $nextSurvey;
        }
        $chainingResponseLink->nextsrid = $oResponse->id;
        if(!$chainingResponseLink->save()) {
            $this->log("Unable to save response link for {$oResponse->id} survey {$surveyId} linked with response {$responseId} for $nextSurvey",\CLogger::LEVEL_ERROR);
            $this->log(CVarDumper::dumpAsString($chainingResponseLink->getErrors()),CLogger::LEVEL_ERROR);
        }
        /* Get email and send */
        $nextMessage = $this->get($nextMessageSetting, 'Survey', $surveyId, "invite");
        if($currentChoice && $nextMessage==='') {
            $nextMessage = $this->get('nextMessage', 'Survey', $surveyId, "invite");
        }
        if($nextMessage==='') {
            $nextMessage = 'invite';
        }

        if($this->_hasTokenTable($oNextSurvey->sid) && !$oNextSurvey->getIsAnonymized() && $oToken) {
            if($this->_sendSurveyChainingTokenEmail($nextSurvey,$oToken,$nextMessage,$oResponse->id)) {
                // All done
                return;
            }
        }
        if($this->_reloadAnyResponseExist()) {
            $this->_sendSurveyChainingReloadEmail($nextSurvey,$oResponse->id,$sEmail,$nextMessage,$oToken);
        } else {
            $this->log("reloadAnyResponse not activated for surveyChaining, can create next reponse",'error');
        }
    }

    /**
     * send email with SURVEYURL to new survey using reloadAnyResponse plugin
     * @param integer $iSurvey
     * @param integer $iResponse
     * @param string $sEmail
     * @param string $mailType
     * @throw Exception
     * @return boolean
     */
    private function _sendSurveyChainingReloadEmail($nextSurvey,$iResponse,$sEmail,$mailType = 'invite')
    {
        $oNextSurvey = Survey::model()->findByPk($nextSurvey);
        if ($this->_hasTokenTable($oNextSurvey->sid)) {
            /* Always create token */
            $oToken = $this->_createToken($nextSurvey);
            /* Set token to exiting response if needed */
            if($oToken && !$oNextSurvey->getIsAnonymized()) {
                $oResponse = Response::model($nextSurvey)->findByPk($iResponse);
                if($oResponse && $oResponse->token) {
                    $oToken->token = $oResponse->token;
                    $oToken->save();
                }
            }
        }
        $aReplacement = array();
        /* Get survey link */
        $token = isset($oToken->token) ? $oToken->token : null;
        $responseLink = \reloadAnyResponse\models\responseLink::setResponseLink($nextSurvey,$iResponse,$token);
        if($responseLink->hasErrors()) {
            $this->log("Unable to save response reload link for {$iResponse} survey {$nextSurvey}",\CLogger::LEVEL_ERROR);
            $this->log(CVarDumper::dumpAsString($responseLink->getErrors()),CLogger::LEVEL_ERROR);
            return false;
        }
        $aReplacements = array();
        $aReplacements["SURVEYURL"] = $responseLink->getStartUrl();
        $this->log("Try to send an email to $sEmail for $nextSurvey with responseLink",\CLogger::LEVEL_INFO);
        if($this->_sendSurveyChainingEmail($nextSurvey,$sEmail,Yii::app()->getLanguage(),$mailType,$aReplacements) ) {
            return true;
        }
        return false;
    }

    /**
     * send email with SURVEYURL to new survey
     * @param integer $iSurvey
     * @param \Tokent $oToken \Token
     * @param string $mailType
     * @throw Exception
     * @return boolean
     */
    private function _sendSurveyChainingTokenEmail($nextSurvey,$oToken,$mailType = 'invite',$srid=null)
    {
        $sToken = $oToken->token;
        $sLanguage = $oToken->language;
        $aReplacements = array();
        foreach($oToken->attributes as $attribute=>$value){
            $aReplacements[strtoupper($attribute)]=$value;
        }
        $aReplacements["SURVEYURL"] = Yii::app()->getController()->createAbsoluteUrl("/survey/index",array('sid'=>$nextSurvey,'lang'=>$sLanguage,'token'=>$sToken,'srid'=>$srid));
        if($this->_sendSurveyChainingEmail($nextSurvey,$oToken->email,$sLanguage,$mailType,$aReplacements) ) {
            /* @todo did we need to test sent ? */
            $oToken->sent = dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i", App()->getConfig("timeadjust"));
            $oToken->save();
            return true;
        }
        return false;
    }

    /**
     * function to send email
     * @param int $nextSurvey
     * @param string $language
     * @param string $sSendTo email or list of emails separated by ;
     * @param string $mailType
     * @param array $aReplacements
     * @return boolean (success or not)
     */
    private function _sendSurveyChainingEmail($nextSurvey,$sSendTo,$sLanguage = '',$mailType = 'invite',$aReplacements = array()) {
        global $maildebug;
        if(!in_array($mailType,array('invite','remind','register','confirm','admin_notification','admin_responses')) ) {
            if(defined('YII_DEBUG') && YII_DEBUG) {
                throw new Exception("Invalid mail type set ({$mailType}).");
            }
            return false;
        }
        $oSurvey = Survey::model()->findByPk($nextSurvey);
        if(!in_array($sLanguage,$oSurvey->getAllLanguages())) {
            $sLanguage = $oSurvey->language;
        }
        $oSurveyLanguage = SurveyLanguageSetting::model()->findByPk(array('surveyls_survey_id'=>$nextSurvey,'surveyls_language'=>$sLanguage));

        $attSubject = 'surveyls_email_'.$mailType.'_subj';
        $attMessage = 'surveyls_email_'.$mailType;
        if(in_array($mailType,array('admin_notification','admin_responses')) ) {
            $attSubject = 'email_'.$mailType.'_subj';
            $attMessage = 'email_'.$mailType;
        }
        $sSubject = $oSurveyLanguage->$attSubject;
        $sMessage = $oSurveyLanguage->$attMessage;
        $useHtmlEmail = $oSurvey->htmlemail == "Y";
        $aReplacementFields=$aReplacements;
        $aReplacementFields["ADMINNAME"]=$oSurvey->admin;
        $aReplacementFields["ADMINEMAIL"]=$oSurvey->adminemail;
        $aReplacementFields["SURVEYNAME"]=$oSurveyLanguage->surveyls_title;
        $aReplacementFields["SURVEYDESCRIPTION"]=$oSurveyLanguage->surveyls_description;
        $aReplacementFields["EXPIRY"]=$oSurvey->expires;
        if(isset($aReplacementFields["SURVEYURL"])) {
            $url = $aReplacementFields["SURVEYURL"];
            if ($useHtmlEmail) {
                $aReplacementFields["SURVEYURL"] = "<a href='{$url}'>" . htmlspecialchars($url) . '</a>';
            }
            $sSubject = str_replace("@@SURVEYURL@@", $url, $sSubject);
            $sMessage = str_replace("@@SURVEYURL@@", $url, $sMessage);
        } // Send a warning if not set ?

        $sSubject = $this->_EMProcessString($sSubject,$aReplacementFields);
        $sMessage = $this->_EMProcessString($sMessage,$aReplacementFields);
        $mailFromName = $oSurvey->admin;
        $mailFromMail = empty($oSurvey->adminemail) ? App()->getConfig('siteadminemail') : $oSurvey->adminemail;
        $sFrom = !empty($mailFromName) ? "{$mailFromName} <{$mailFromMail}>" : $mailFromMail;
        $sBounce=$oSurvey->bounce_email;
        $aRecipient = explode(";", $this->_EMProcessString($sSendTo,$aReplacementFields));
        foreach ($aRecipient as $sRecipient) {
            $sRecipient = trim($sRecipient);
            if (validateEmailAddress($sRecipient)) {
                $aEmailTo[] = $sRecipient;
            }
        }
        $sended = false;
        if(!empty($aEmailTo)) {
            foreach($aEmailTo as $sEmail) {
                $this->log("SendEmailMessage to $sEmail",\CLogger::LEVEL_TRACE);
                if(SendEmailMessage($sMessage, $sSubject, $sEmail, $sFrom, App()->getConfig("sitename"), $useHtmlEmail, $sBounce)) {
                    $sended = true;
                } else {
                    $this->log($this->_translate("Unable to send email with debug : {$maildebug}"),\CLogger::LEVEL_ERROR);
                }
            }
        }
        return $sended;
    }
    /**
     * Create a token for a survey
     * @param integer $nextSurvey id
     * @param string $originalEmail
     * @param array $aAttributes to create
     * @return \Token|null
     */
    private function _createToken($nextSurvey, $originalEmail = "", $aAttributes = array())
    {
        $oToken = Token::create($nextSurvey);
        $aValidAttributes = $oToken->attributes;
        foreach($aAttributes as $attribute=>$value) {
            if(in_array($attribute,$aValidAttributes)) {
                $oToken->$attribute = $value;
            }
        }
        $oToken->validfrom = date("Y-m-d H:i:s");
        /* Fixing email */
        if(!empty($originalEmail)) {
            $aEmails = preg_split("/(,|;)/", $originalEmail);
            $aFixedEmails = array();
            foreach($aEmails as $emailAdress) {
                $emailAdress = trim($emailAdress);
                if(validateEmailAddress($emailAdress)) {
                    $aFixedEmails[] = $emailAdress;
                }
            }
            if(!empty($originalEmail) && count($aEmails) != count($aFixedEmails)) {
                $this->log($this->_translate("Invalid email adress in $originalEmail"),\CLogger::LEVEL_ERROR);
            }
            if(!empty($aFixedEmails)) {
                $oToken->email = implode(";",$aFixedEmails);
            }
        }
        $oToken->generateToken();
        $language = App()->getLanguage();
        if(!in_array($language,Survey::model()->findByPk($nextSurvey)->getAllLanguages())) {
            $language = Survey::model()->findByPk($nextSurvey)->language;
        }
        $oToken->language = $language;
        if(!$oToken->save()) {
            $this->log($this->_translate("Unable to create token for $nextSurvey"),\CLogger::LEVEL_ERROR);
            return null;
        }
        return $oToken;
    }
    /**
     * Update some Yii and LS config
     * Set some var
     */
    private function _setConfig()
    {
        Yii::setPathOfAlias('surveyChaining', dirname(__FILE__));
    }

  /**
   * @inheritdoc adding string, by default current event
   * @param string
   */
  public function log($message, $level = \CLogger::LEVEL_TRACE,$logDetail = null)
  {
    if(!$logDetail && $this->getEvent()) {
      $logDetail = $this->getEvent()->getEventName();
    } // What to put if no event ?
    //parent::log($message, $level);
    Yii::log($message, $level,'application.plugins.'.get_class($this).".".$logDetail);
  }

  /**
   * set and fix DB and table
   * @return void
   */
  private function _setDb()
  {
    if($this->get('dbversion',null,null,0) >= $this->dbversion) {
      return;
    }
    if (!$this->api->tableExists($this, 'chainingResponseLink')) {
      $this->api->createTable($this, 'chainingResponseLink', array(
          'id' => 'pk',
          'prevsid'=>'int',
          'prevsrid'=>'int',
          'nextsid'=>'int',
          'nextsrid'=>'int',
      ));
      $this->set('dbversion',$this->dbversion);
      Notification::broadcast(array(
          'title' => gT('Database update'),
          'message' => sprintf($this->_translate('The database for plugin %s has been created (version %s).'),get_class($this),$this->dbversion),
          'display_class' => 'success',
      ),User::model()->getSuperAdmins());
      $this->log(sprintf('The database for plugin %s has been created (version %s).',get_class($this),$this->dbversion),\CLogger::LEVEL_INFO);
      return; // No need upgrade if created
    }
    /* Not used currently, adding function when need update */
    $this->set('dbversion',$this->dbversion);
    Notification::broadcast(array(
        'title' => gT('Database update'),
        'message' => sprintf($this->_translate('The database for plugin %s has been upgraded to version %s.'),get_class($this),$this->dbversion),
        'display_class' => 'success',
    ),User::model()->getSuperAdmins());
    $this->log(sprintf('The database for plugin %s has been upgraded to version %s.',get_class($this),$this->dbversion),\CLogger::LEVEL_INFO);
  }

    /**
     * Get the help for a survey in settings
    * @param $surveyId
    * @param $selectedSurveyId
    * @return null|string html to be shown
    */
    private function _getHelpFoSurveySetting($surveyId,$selectedSurveyId)
    {
        if(!$selectedSurveyId) {
            return null;
        }
        $oNextSurvey = Survey::model()->findByPk($selectedSurveyId);
        if(!$oNextSurvey) {
            return CHtml::tag("div",array('class'=>"text-warning"),$this->_translate("Warning : previous survey selected don't exist currently."));
        }
        $aStringReturn = array();
        $surveyLink = CHtml::link($this->_translate("survey selected"),array('admin/survey/sa/view','surveyid'=>$selectedSurveyId));
        if(!$this->_hasTokenTable($oNextSurvey->sid) && !$this->_reloadAnyResponseExist()) {
            $aStringReturn[] = CHtml::tag("div",array('class'=>"text-danger"),sprintf($this->_translate("Warning : current %s don't have token table, you must enable token before."),$surveyLink));
        }
        if($this->_hasTokenTable($oNextSurvey->sid) && $oNextSurvey->tokenanswerspersistence!="Y" && $this->_reloadAnyResponseExist()) {
            $aStringReturn[] = CHtml::tag("div",array('class'=>"text-danger"),sprintf($this->_translate("Warning : current %s don't have token answer persistance enable."),$surveyLink));
        }
        $aSameCodes = $this->_getSameCodes($surveyId,$selectedSurveyId);
        if(empty($aSameCodes)) {
            $aStringReturn[] = CHtml::tag("div",array('class'=>"text-danger"),sprintf($this->_translate("Warning : %s and current survey didn't have any correspondig question."),$surveyLink));
        } else {
            $aStringReturn[] = CHtml::tag("div",array('class'=>"text-info"),sprintf($this->_translate("The %s and current survey have this correspondig question: %s"),$surveyLink,implode(", ",$aSameCodes)));
            /* Find if answersAsReadonly is activated */
            $oAnswersAsReadonly = Plugin::model()->find("name = :name",array(":name"=>'answersAsReadonly'));
            if ($oAnswersAsReadonly && $oAnswersAsReadonly->active) {
                /* Link broke lsadminpanel … */
                /* @todo : control if user have permission on $selectedSurveyId */
                if(Permission::model()->hasSurveyPermission($selectedSurveyId,'surveysettings','update')){
                    $aStringReturn[] = CHtml::link(
                        $this->_translate("Set common question to read only"),
                        array("plugins/direct",'plugin' => get_class(),'sid'=>$surveyId,'destsid'=>$selectedSurveyId),
                        array('class'=>'btn btn-warning btn-xs ajax-surveychaining')
                    );
                } else {
                    $aStringReturn[] = CHtml::tag("div",array('class'=>"text-warning"),$this->_translate("Warning : You don't have enough permission on selected survey."));
                }
                //~ $url = Yii::app()->createUrl("plugins/direct", array('plugin' => get_class(),'sid'=>$surveyId));
                //~ $aStringReturn[] = CHtml::htmlButton(
                    //~ $this->_translate("Set common question to read only"),
                    //~ array('class'=>'btn btn-warning btn-xs ajax-surveychaining','data-url'=>$url)//)
                //~ );
            }
        }
        return implode("\n",$aStringReturn);
    }
    /**
    * Get corresponding questioon code correponding for 2 surveys
    * @param $firstSurveyId
    * @param $secondSurveyId
    * @return string[] key is column of 1st survey, value is EM code of question
    */
    private function _getSameCodes($firstSurveyId,$secondSurveyId)
    {
        if(!Yii::getPathOfAlias('getQuestionInformation')) {
            return array();
        }
        $firstSurveyCodes =  \getQuestionInformation\helpers\surveyCodeHelper::getAllQuestions($firstSurveyId);
        $secondSurveyCodes =  \getQuestionInformation\helpers\surveyCodeHelper::getAllQuestions($secondSurveyId);
        return array_intersect($firstSurveyCodes,$secondSurveyCodes);
    }

    /**
     * Check if reloadResponseExist
     */
    private function _reloadAnyResponseExist()
    {
        return (bool) Yii::getPathOfAlias('reloadAnyResponse');
    }

    /**
     * render json
     * @param mixed
     * @return void
     */
    private function _renderJson($data=null) {
        if(is_array($data)) {
            $data = array_merge(array('hasPermission'=>true,'loggedIn'=>true),$data);
        }
        header('Content-type: application/json; charset=utf-8');
        echo json_encode($data);
        Yii::app()->end();
    }
   
  /**
   * get translation
   * @param string $string to translate
   * @param string escape mode
   * @param string language, current by default
   * @return string
   */
    private function _translate($string, $sEscapeMode = 'unescaped', $sLanguage = null)
    {
        if(is_callable(array($this, 'gT'))) {
            return $this->gT($string,$sEscapeMode,$sLanguage);
        }
        return gT($string,$sEscapeMode,$sLanguage);
    }

    /**
     * Check if survey as a token table
     * For 2.X compatibility
     * @see \Survey::model()->getHasTokensTable()
     * @param integer $surveyId
     * @return boolean
     */
     private function _hasTokenTable($surveyId)
     {
        Yii::import('application.helpers.common_helper', true);
        return tableExists('tokens_'.$surveyId);
     }

    /**
     * Process a string via expression manager (static way)
     * @param string $string
     * @param string[] $replacementFields
     * @return string
     */
    private function _EMProcessString($string, $replacementFields = array())
    {
        if (intval(Yii::app()->getConfig('versionnumber'))<3) {
            return \LimeExpressionManager::ProcessString($string, null, $replacementFields, false, 3, 0, false, false, true);
        }
        if (version_compare(Yii::app()->getConfig('versionnumber'), "3.6.2", "<")) {
            return \LimeExpressionManager::ProcessString($string, null, $replacementFields, 3, 0, false, false, true);
        }
        return \LimeExpressionManager::ProcessStepString($string, $replacementFields, 3, true);
    }
}
