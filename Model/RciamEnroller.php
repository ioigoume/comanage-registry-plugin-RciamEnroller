<?php

class RciamEnroller extends AppModel
{
  // Required by COmanage Plugins
  public $cmPluginType = 'enroller';
  // Default display field for cake generated views
  public $displayField = 'name';
  // Add behaviors
  public $actsAs = array('Containable');
  // Document foreign keys
  public $hasMany = array(
    // An enroller can be associated with one or many EOFs
    'RciamEnrollerEof' => array(
      'className' => 'RciamEnroller.RciamEnrollerEof',
      'dependent' => true,
      'foreignKey' => 'rciam_enroller_id',
    ),
  );
  
  // Document foreign keys
  public $cmPluginHasMany = array(
    'CoEnrollmentFlow' => array('RciamEnrollerEof'),
  );
  
  // Validation rules for table elements
  // We always need to provide validation values for foreign keys since they are used for the calculation of the implied CO Id
  public $validate = array(
    'co_id' => array(
      'rule' => 'numeric',
      'required' => true,
      'message' => 'A CO ID must be provided',
    ),
    'status' => array(
      'rule' => array(
        'inList',
        array(
          SuspendableStatusEnum::Active,
          SuspendableStatusEnum::Suspended
        )
      ),
      'required' => true,
      'message' => 'A valid status must be selected'
    ),
    'redirect_msg' => array(
      'rule' => 'notBlank',
      'required' => false,
      'allowEmpty' => true
    ),
    'return' => array(
      'rule' => 'notBlank',
      'required' => false,
      'allowEmpty' => true
    ),
  );
  
  /**
   * Expose menu items.
   *
   * @ since COmanage Registry v2.0.0
   * @ return Array with menu location type as key and array of labels, controllers, actions as values.
   */
  public function cmPluginMenus() {
    $this->log(__METHOD__ . '::@', LOG_DEBUG);
    return array(
      'coconfig' => array(_txt('ct.rciam_enroller.2') =>
        array('controller' => 'rciam_enrollers',
              'action'     => 'configure'))
    );
  }
  
  /**
   * @param String $attribute_type cmpEnrollmentAttribute
   * @param String $attribute_value Value of Enrollment Attribute
   * @param Integer $co_id
   * @return array|null
   */
  public function getCoPersonMatches($attribute_type, $attribute_value, $co_id) {
    $this->log(__METHOD__ . "::@", LOG_DEBUG);
    
    switch ($attribute_type) {
      case "mail":
        $official = EmailAddressEnum::Official;
        $active = SuspendableStatusEnum::Active;
        // Only need the COPerson's email to be verified and not the ones enlisted in the OrgIdentities
        // We want the co people that we will retrieve to have the email verified at least in one linked idp. If we fetch the account then we will
        // fetch all the idps regardless of the email confirmation status.
        //$query_string = "select distinct names.given, names.family, mail.mail as pemail, people.id as pid, people.status as pstatus, oid.id as OId, oid.authn_authority as IdP, mailOid.mail as OIdEmail, cos.name as CO" .
        $query_string = "select distinct names.given, names.family, mail.mail as pemail, people.id as pid, people.status as pstatus, cos.name as CO" .
            " from cm_email_addresses as mail" .
            " inner join cm_names names on mail.co_person_id = names.co_person_id and not mail.deleted and mail.email_address_id is null and mail.type='{$official}' and mail.verified=true" .
            " inner join cm_co_people as people on people.id = mail.co_person_id and people.co_id = {$co_id} and people.status='A' and not people.deleted and people.co_person_id is null" .
            " inner join cm_cos as cos on people.co_id=cos.id and cos.status='{$active}'" .
            " inner join cm_co_org_identity_links as links on people.id=links.co_person_id and not links.deleted and links.co_org_identity_link_id is null" .
            " inner join cm_org_identities as oid on oid.id=links.org_identity_id and not oid.deleted and oid.org_identity_id is null" .
            " inner join cm_email_addresses as mailOid on mailOid.org_identity_id = oid.id and not mailOid.deleted and mailOid.email_address_id is null and mailOid.type='{$official}'" .
            " where mail.mail='{$attribute_value}' or mailOid.mail='{$attribute_value}'";
        $this->log(__METHOD__ . "::query => " . $query_string, LOG_DEBUG);
        $registrations = $this->query($query_string);
        // For each registration i want to find all the linked idps and present them to the user
        $this->OrgIdentity = ClassRegistry::init('OrgIdentity');
        // An array with all the idps associated with this user
        $orgIdentities_list = array();
        foreach($registrations as &$registration){
          $pid = $registration[0]['pid'];
          // Get list of IdPs for each user
          $idpsList = $this->OrgIdentity->find('list', array(
              'fields' => array(
                'OrgIdentity.id',
                'OrgIdentity.authn_authority'
              ),
              'contain' => false,
              'conditions' => array(
                'CoOrgIdentityLink.co_person_id = ' . $pid,
                'OrgIdentity.authn_authority is not null',
              ),
              'joins' => array(
                array(
                  'table' => 'co_org_identity_links',
                  'alias' => 'CoOrgIdentityLink',
                  'type' => 'INNER',
                  'conditions' => array(
                    'CoOrgIdentityLink.org_identity_id = OrgIdentity.id',
                  )
                ),
              ),
            )
          );
          // Update the idps list in the registration table
          if(!empty($idpsList)) {
            $registration[0]['idp'] = $idpsList;
            $orgIdentities_list += $idpsList;
          }
        }
        if(!empty($registrations) && !empty($orgIdentities_list)){
          return array($registrations, $orgIdentities_list);
        }
        break;
      default:
        $this->log(__METHOD__ . "::there is no action for this attribute type:" . $attribute_type, LOG_DEBUG);
    }
    
    return null;
  }
  
  /**
   * @param Integer $co_id
   * @param boolean $non_cou
   * @return mixed
   */
  public function getEnrollmentFlows($co_id, $non_cou=false) {
    if($non_cou) {
      // I exclude all the EOF that refer to COU enrollment
      $this->CoEnrollmentAttribute = ClassRegistry::init('CoEnrollmentAttribute');
      $args = array();
      $args['conditions']['CoEnrollmentAttribute.attribute LIKE'] = '%cou%';
      $args['conditions']['CoEnrollmentAttribute.deleted'] = false;
      $args['fields'] = array('CoEnrollmentAttribute.co_enrollment_flow_id');
      $args['contain'] = false;
      $cou_eof = $this->CoEnrollmentAttribute->find('list', $args);
      unset($args);
    }
    $args = array();
    $args['conditions']['CoEnrollmentFlow.co_id'] = $co_id;
    $args['conditions']['CoEnrollmentFlow.deleted'] = false;
    $args['conditions']['CoEnrollmentFlow.status'] = EnrollmentFlowStatusEnum::Active;
    if($non_cou){
      // Get the enrollment flows from the current CO filtered out from the COUs
      $args['conditions']['NOT']['CoEnrollmentFlow.id'] = $cou_eof;
    }
    $args['fields'] = array('CoEnrollmentFlow.id', 'CoEnrollmentFlow.name');
    $args['contain'] = false;
    $this->CoEnrollmentFlow = ClassRegistry::init('CoEnrollmentFlow');
    return $this->CoEnrollmentFlow->find('list', $args);
  }
  
  /**
   * @param Integer $co_id
   * @return array|null
   */
  public function getConfiguration($co_id) {
    // Get all the config data. Even the EOFs that i have now deleted
    $args = array();
    $args['conditions']['RciamEnroller.co_id'] = $co_id;
    $args['contain'] = array(
      'RciamEnrollerEof' => array(
        'fields'      => array(
          'RciamEnrollerEof.co_enrollment_flow_id',
          'RciamEnrollerEof.id',
          'RciamEnrollerEof.mode'),
      ),
    );
    $data = $this->find('first', $args);
    // There is no configuration available for the plugin. Abort
    if(empty($data)) {
      return null;
    }
    // Make a list out of all available EOFs in the database
    $data += array( 'RciamEnrollerEof_list' => Hash::combine($data, 'RciamEnrollerEof.{n}.co_enrollment_flow_id', 'RciamEnrollerEof.{n}.id'));
    
    return $data;
  }
  
  
  /**
   * @param array $envAssociativeArray
   * @return array
   */
  public function getAttrValues($envAssociativeArray=[]) {
    // If the user provided no array then try to fecth the values from the environment
    // We assume that the shibboleth apache2 module will expose the attributes in the environment
    // The $getVal variable is a function that represents either the getenv function or a wrapper around the array of attribute values
    // TODO: In php 7.1 getenv returns an associative array and requires no key. If i move to a newer version reconstruct the following two lines
    $getVal = empty($envAssociativeArray) ? function($attr) {return !empty(getenv($attr)) ? getenv($attr) : "";} :
                                            function($attr) use($envAssociativeArray) {return !empty($envAssociativeArray[$attr]) ? $envAssociativeArray[$attr] : "";};
    
    // Get the list of the cmp enrollment attributes
    $args = array();
    //$args['conditions'][] = 'CmpEnrollmentAttribute.env_name like \'%mail%\'';
    $args['conditions']['NOT']['CmpEnrollmentAttribute.env_name'] = '';
    $args['fields'] = array('CmpEnrollmentAttribute.env_name', 'CmpEnrollmentAttribute.env_name');
    $args['contain'] = false;
    $cmpEnrollmentAttributes = ClassRegistry::init('CmpEnrollmentAttribute');
    $attribute_list = $cmpEnrollmentAttributes->find('list', $args);
    
    if(!empty($attribute_list) && is_array($attribute_list)){
      $attr_data = array();
      foreach($attribute_list as $attr){
        $attr_data[$attr] = $getVal($attr);
      }
      return array($attribute_list, $attr_data);
    } else {
      $this->log(__METHOD__ . '::no cmp attribute list found in COmanage configuration.', LOG_DEBUG);
      return array();
    }
  }
  
  /**
   * @param $identifier,  This is the EPUID attribute
   * @param $co_id,       Each epuid is unique for each CO.
   * @return bool|null
   */
  public function findDuplicateOrgId($identifier, $co_id) {
    if(empty($identifier) || empty($co_id)) {
      return null;
    }
  
    $this->OrgIdentity = ClassRegistry::init('OrgIdentity');
    $args = array();
    $args['joins'][0]['table'] = 'identifiers';
    $args['joins'][0]['alias'] = 'Identifier';
    $args['joins'][0]['type'] = 'INNER';
    $args['joins'][0]['conditions'][0] = 'OrgIdentity.id=Identifier.org_identity_id';
    $args['conditions']['OrgIdentity.co_id'] = $co_id;
    $args['conditions']['Identifier.identifier'] = $identifier;
    $args['conditions']['Identifier.deleted'] = false;
    $args['conditions']['OrgIdentity.deleted'] = false;
    $args['fields'] = ['Identifier.org_identity_id'];
    $args['contain'] = false;
    $es = $this->OrgIdentity->find('all', $args);
  
    if(!empty($es)) {
      return true;
    }
  
    return false;
  }
  
  /**
   * @param $identifier
   * @param $co_id
   * @return array
   */
  public function findCoPersonforIdentifier($identifier, $co_id=null){
    if(empty($identifier)) {
      return [];
    }
  
    $this->CoPerson = ClassRegistry::init('CoPerson');
    $args = array();
    $args['joins'][0]['table'] = 'identifiers';
    $args['joins'][0]['alias'] = 'Identifier';
    $args['joins'][0]['type'] = 'INNER';
    $args['joins'][0]['conditions'][0] = 'CoPerson.id=Identifier.co_person_id';
    if($co_id) {
      $args['conditions']['Identifier.co_id'] = $co_id;
    }
    $args['conditions']['Identifier.identifier'] = $identifier;
    $args['conditions']['Identifier.deleted'] = false;
    $args['conditions']['CoPerson.deleted'] = false;
    $args['contain'] = false;
    return $this->CoPerson->find('all', $args);
  }
}


