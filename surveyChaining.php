<?php
/**
 * Chaining survey
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2018 Denis Chenu <http://www.sondages.pro>
 * @license GPL v3
 * @version 0.0.0
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
            'label' => $this->gT('survey chaining'),
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
        $aNextSettings = array(
            'nextSurvey' => array(
                'type'=>'select',
                'htmlOptions'=>array(
                    'empty'=>$this->gT("None"),
                ),
                'label'=>$this->gT("Next survey (by default)."),
                'options'=>CHtml::listData($aWholeSurveys,'sid','defaultlanguage.surveyls_title'),
                'current'=>$this->get('nextSurvey', 'Survey', $surveyId,null),
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
                'options'=>CHtml::listData($oaQuestion,'title','question'),
                'current'=>$this->get('choiceQuestion', 'Survey', $surveyId,null),
                'help' => $this->gT("Only single choice question type can be used for survey selection. The list of available answer update after save this value."),
            ),
        );
        $aSettings[$this->gT("Question for conditionnal surveys")] = $aNextQuestionSettings;

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
                            'empty'=>$this->gT("None"),
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
}
