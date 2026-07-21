<?php
/**
 * WhatsApp Groups Model
 * Manages WhatsApp group data synced from wa-hub
 */

Yii::import('application.models.X2Model');

class WhatsAppGroups extends X2Model {

    public $supportsWorkflow = false;

    /**
     * Returns the static model of the specified AR class.
     * @return WhatsAppGroups
     */
    public static function model($className = __CLASS__) {
        return parent::model($className);
    }

    /**
     * @return string the associated database table name
     */
    public function tableName() {
        return 'wa_groups';
    }

    /**
     * @return array validation rules for model attributes.
     */
    public function rules() {
        return array(
            array('groupId, groupName', 'required'),
            array('groupId', 'unique'),
            array('groupName, subject, phoneNumber, description', 'safe'),
            array('isSynced', 'boolean'),
            array('listId', 'numerical', 'integerOnly' => true, 'allowEmpty' => true),
        );
    }

    /**
     * @return array relational rules.
     */
    public function relations() {
        return array(
            'members' => array(self::HAS_MANY, 'WhatsAppGroupMembers', 'groupId'),
            'list' => array(self::BELONGS_TO, 'X2List', 'listId'),
        );
    }

    /**
     * @return array customized attribute labels (name=>label)
     */
    public function attributeLabels() {
        return array(
            'id' => 'ID',
            'groupId' => 'WhatsApp Group ID',
            'groupName' => 'Group Name',
            'subject' => 'Subject',
            'phoneNumber' => 'Creator Phone',
            'isSynced' => 'Synced',
            'description' => 'Description',
            'listId' => 'Linked List',
            'createdAt' => 'Created',
            'lastSyncedAt' => 'Last Synced',
            'updatedAt' => 'Updated',
        );
    }

    public function search() {
        $criteria = new CDbCriteria;
        $criteria->compare('id', $this->id);
        $criteria->compare('groupId', $this->groupId, true);
        $criteria->compare('groupName', $this->groupName, true);
        $criteria->compare('subject', $this->subject, true);
        $criteria->compare('isSynced', $this->isSynced);
        $criteria->order = 'createdAt DESC';
        return new CActiveDataProvider($this, array('criteria' => $criteria, 'pagination' => array('pageSize' => 50)));
    }

    /**
     * Get member count for this group
     */
    public function getMemberCount() {
        return WhatsAppGroupMembers::model()->countByAttributes(array('groupId' => $this->id));
    }

    /**
     * Get all members
     */
    public function getMembers() {
        return WhatsAppGroupMembers::model()->findAllByAttributes(array('groupId' => $this->id), array('order' => 'joinedAt DESC'));
    }
}
?>
