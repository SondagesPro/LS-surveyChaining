<?php
/**
 */
namespace surveyChaining\models;
use CActiveRecord;
class chainingResponseLink extends CActiveRecord
{
/**
 * Class surveyChaining\models\chainingResponseLink
 *
 * @property integer $id primary key
 * @property integer $prevsid : previous survey id
 * @property integer $prevsrid : previous response id
 * @property integer $nextsid : next survey id
 * @property integer $nextsrid : next response id
*/

    public static function model($className=__CLASS__) {
        return parent::model($className);
    }
    /** @inheritdoc */
    public function tableName()
    {
        return '{{surveychaining_chainingResponseLink}}';
    }

    /** @inheritdoc */
    public function rules()
    {
        /* @todo : add unique for [$nextsid,$nextsrid], there can be only one previous survey for a new response */
        return parent::rules();
    }

    /**
     * Delete all links related to a survey
     * @param int $surveyid
     * @return integer number of deleted lines
     */
    public static function deleteBySurvey($surveyid)
    {
        return self::model()->deleteAll(
            "prevsid = :prevsid OR nextsid = :nextsid",
            array(':prevsid'=>$surveyid,':nextsid'=>$surveyid)
        );
    }

    /**
     * Delete all links related to response in a survey
     * @param int $surveyid
     * @param int $responseid
     * @return integer number of deleted lines
     */
    public static function deleteByResponse($surveyid,$responseid)
    {
        return self::model()->deleteAll(
            "(prevsid = :prevsid AND prevsrid = :prevsrid) OR (nextsid = :nextsid AND nextsrid = :nextsrid",
            array(':prevsid'=>$surveyid,':nextsid'=>$surveyid,':prevsrid'=>$responseid,':nextsrid'=>$responseid)
        );
    }
}
