<?php
/**
 * Process the import_wachtdienst tabel met with the civicrm_api3
 *
 * @author Klaas Eikelbooml (CiviCooP) <klaas.eikelboom@civicoop.org>
 * @date 3-april-2018
 * @license AGPL-3.0
 *
 */
class CRM_Wachtdienstimport_Processor {

  /**
   * Wordt gebruikt voor het inlezen van datum tijd constanten.
   */
  const DATETIMEFORMAT = 'd-m-Y H:i';

  /**
   * Grootte van de verwerkinsstap
   * @var int
   */
  private $stepsize;

  /**
   * CRM_Wachtdienstimport_Processor.
   *
   * @param $stepsize
   */
  public function __construct($stepsize = 10) {
    $this->stepsize = $stepsize;
  }

  /**
   * Rekent het aantal stappen uit (gebruikt hierbij stepsize).
   * @return int
   */
  private function calcSteps() {
    $calcRows = CRM_Core_DAO::singleValueQuery('SELECT count(1) FROM import_wachtdienst WHERE processed = %1', array(
      '1' => array('N', 'String'),
    ));
    return ceil($calcRows / $this->stepsize);
  }


  /**
   * Voert één stap in de verwerking uit.
   *
   * @param \CRM_Queue_TaskContext $ctx
   * @param $testOption
   *
   * @return bool
   */
  public function process(CRM_Queue_TaskContext $ctx,$testOption) {
    $dao = CRM_Core_DAO::executeQuery('SELECT * FROM import_wachtdienst WHERE processed = %1 LIMIT %2', array(
      '1' => array('N', 'String'),
      '2' => array($this->stepsize, 'Integer'),
    ));
    try {
      while ($dao->fetch()) {
        $this->processRecord($dao,$testOption);
      }
    } catch (Exception $ex) {
      Civi::log()->info($ex);
    }
    return TRUE;
  }

  /**
   * Vult de queue die de verwerking uitvoert.
   *
   * @param $queue
   * @param $testOption
   */
  public function fillQueue($queue, $testOption) {
    $calcSteps = $this->calcSteps();
    for ($i = 0; $i <= $calcSteps; $i++) {
      $task = new CRM_Queue_Task(
        array(
          $this,
          'process',
        ), //call back method
        array('testOption'=>$testOption), //parameters,
        "Processed " . $i * $this->stepsize . " rows"
      );
      $queue->createItem($task);
    }
  }

  /**
   * Verwerkt precies één record
   * @param $dao
   */
  private function processRecord($dao,$testOption) {

    /* the array errors is used by the processing
       functions to store errors so they can be
       examined later. Now all the processing functions skip
       processing if they find and error.
    */
    $errors = array();

    try {

      $this->processCheck($dao, $errors,$testOption);
    } catch (Exception $ex) {
      $errors[] = $ex;
    }
    if (empty($errors)) {
      CRM_Core_DAO::executeQuery('UPDATE import_wachtdienst SET processed = %2,message=null WHERE id=%1', array(
        1 => array($dao->id, 'Integer'),
        2 => array('S', 'String'),
      ));
    }
    else {
      $message = serialize($errors);
      CRM_Core_DAO::executeQuery('UPDATE import_wachtdienst SET processed = %2, message=%3 WHERE id=%1', array(
        1 => array($dao->id, 'Integer'),
        2 => array('F', 'String'),
        3 => array($message, 'String'),
      ));
    }
  }

  /**
   *  voort controles uit op het aangeleverde record
   *  blijken er geen errors dan wordt de activiteit toegevoegd.
   *
   * @param $dao
   * @param $errors
   */
  private function processCheck($dao, &$errors,$testOption) {

    if($testOption==='D'){
      $errors[]='Dry Run - Record niet toegevoegd';
    }

    try{
      civicrm_api3('Contact','getsingle',array(
        'id' => $dao->contact_id,
      ));
    } catch (Exception $ex){
      $errors[] = "Contact id {$dao->contact_id} werd niet gevonden";
    }

    // controleer of contact_id geldige gebruiker is

    // check of RiZiv bestaat

    try{
      $arts_id = civicrm_api3('Contact','getvalue',array(
          'return' => "id",
          'external_identifier' => $dao->riziv,
          'contact_sub_type' => "Arts",
      ));
    } catch (Exception $ex){
      $errors[] = "De arts met riziv {$dao->riziv} kon niet gevonden worden";
    }

    // check of wachtdienst bestaat en van het juiste type is

    try{
      $wachtdienst_id = civicrm_api3('Contact','getvalue',array(
        'id' => $dao->wachtdienst_id,
        'contact_sub_type' => 'Wachtdienstonderdeel',
        'return' => 'id',
      ));
    } catch (Exception $ex){
      $errors[] = "De wachtdienst met id {$dao->wachtdienst_id} kon niet gevonden worden";
    }


    $dtStart = DateTime::createFromFormat(self::DATETIMEFORMAT,$dao->datumtijd_start);
    if(!$dtStart){
      $errors[] = "Startdatum onjuiste indeling ($dao->datumtijd_start)";
    }

    $dtEnd = DateTime::createFromFormat(self::DATETIMEFORMAT,$dao->datumtijd_eind);
    if(!$dtEnd){
      $errors[] = "Einddatum onjuiste indeling ($dao->datumtijd_eind)";
    }

    if($dtStart && $dtEnd){
      // door volgorde van de datum wordt de datum sortering gelijk aan
      // alfabetische sortering
      $diff= strcmp($dtEnd->format('Y-m-d H:i'),$dtStart->format('Y-m-d H:i'));
      if($diff<0) {
        $errors[] = "Einddatum {$dao->datumtijd_eind} ligt voor de startijd {$dao->datumtijd_start}";
      }
    }

    if (!empty($errors)) {
      return;
    }

    $config = CRM_Wachtdienstimport_Config::singleton();

    try {
      $activity = civicrm_api3('Activity', 'create', array(
        'is_test' => $testOption==='T'?1:0,
        'status_id' =>  'Completed',
        'activity_type_id' =>  'domusmedica_wachtpostdienst',
        'subject' => 'Wachtdienst '.$dao->arts_naam,
        'activity_date_time' => $dtStart->format('Y-m-d H:i'),
        'source_contact_id' => $dao->contact_id,
        'custom_'.$config->getEindDatumTijdCustomFieldId() => $dtEnd->format('Y-m-d H:i'),
      ));
    }
    catch(Exception $ex){
      $errors[]='Activity Create Error: '.$ex->getMessage();
      return;
    }

    try {
      $result = civicrm_api3('ActivityContact', 'create', array(
        'activity_id' => $activity['id'],
        'contact_id' => $arts_id,
        'record_type_id' => "Activity Assignees",
      ));
    }
    catch(Exception $ex){
      $errors[]='ActivityContact Create Assignee Error: '.$ex->getMessage();
      return;
    }

    try {
      $result = civicrm_api3('ActivityContact', 'create', array(
        'activity_id' => $activity['id'],
        'contact_id' => $wachtdienst_id,
        'record_type_id' => "Activity Targets",
      ));
    }
    catch(Exception $ex){
      $errors[]='ActivityContact Create Target Error: '.$ex->getMessage();
      return;
    }

  }

}