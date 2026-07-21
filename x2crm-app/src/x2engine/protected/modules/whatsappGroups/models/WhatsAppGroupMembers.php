<?php
/**
 * WhatsApp Group Members Model
 * Manages group membership data
 */

Yii::import('application.models.X2Model');

class WhatsAppGroupMembers extends X2Model {

    public $supportsWorkflow = false;

    /**
     * Returns the static model of the specified AR class.
     * @return WhatsAppGroupMembers
     */
    public static function model($className = __CLASS__) {
        return parent::model($className);
    }

    /**
     * @return string the associated database table name
     */
    public function tableName() {
        return 'wa_group_members';
    }

    /**
     * @return array validation rules for model attributes.
     */
    public function rules() {
        return array(
            array('groupId, phone', 'required'),
            array('name', 'safe'),
            array('isAdmin', 'boolean'),
        );
    }

    /**
     * @return array relational rules.
     */
    public function relations() {
        return array(
            'group' => array(self::BELONGS_TO, 'WhatsAppGroups', 'groupId'),
            'contact' => array(self::BELONGS_TO, 'Contacts', 'contactId'),
        );
    }

    /**
     * @return array customized attribute labels (name=>label)
     */
    public function attributeLabels() {
        return array(
            'id' => 'ID',
            'groupId' => 'Group ID',
            'contactId' => 'X2CRM Contact',
            'phone' => 'Phone',
            'name' => 'Name',
            'isAdmin' => 'Administrator',
            'joinedAt' => 'Joined',
        );
    }

    public function search() {
        $criteria = new CDbCriteria;
        $criteria->compare('groupId', $this->groupId);
        $criteria->compare('phone', $this->phone, true);
        $criteria->compare('name', $this->name, true);
        $criteria->compare('isAdmin', $this->isAdmin);
        $criteria->order = 'joinedAt DESC';
        return new CActiveDataProvider($this, array('criteria' => $criteria));
    }

    /**
     * Get linked X2CRM contact if any
     */
    public function getContactName() {
        if ($this->contactId) {
            $contact = Contacts::model()->findByPk($this->contactId);
            return $contact ? $contact->name : '';
        }
        return '';
    }

    /**
     * Link this group member to an X2CRM contact
     */
    public function linkContact($contactId) {
        $this->contactId = $contactId;
        return $this->save();
    }
}
?>
