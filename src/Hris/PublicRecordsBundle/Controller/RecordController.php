<?php
/*
 *
 * Copyright 2012 Human Resource Information System
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301, USA.
 *
 * @since 2012
 * @author John Francis Mukulu <john.f.mukulu@gmail.com>
 *
 */

namespace Hris\PublicRecordsBundle\Controller;

use Doctrine\DBAL\DBALException;
use Doctrine\ORM\EntityManager;
use Doctrine\Tests\Common\Annotations\True;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Doctrine\ORM\QueryBuilder as QueryBuilder;
use FOS\UserBundle\Doctrine;
use Doctrine\ORM\Internal\Hydration\ObjectHydrator as DoctrineHydrator;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Hris\PublicRecordsBundle\Entity\Record;
use Hris\PublicRecordsBundle\Form\RecordType;
use Hris\OrganisationunitBundle\Entity\Organisationunit;
use Doctrine\Common\Collections\ArrayCollection;
use Hris\FormBundle\Entity\Field;
use Hris\FormBundle\Form\FormType;
use Hris\FormBundle\Form\DesignFormType;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\HttpFoundation\JsonResponse;
use JMS\SecurityExtraBundle\Annotation\Secure;
use DateTime;

/**
 * Record controller.
 *
 * @Route("/public_record")
 */
class RecordController extends Controller
{

    /**
     * Lists all Record entities.
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @Route("/", name="public_public_record")
     * @Route("/list", name="public_record_list")
     * @Method("GET")
     * @Template()
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('HrisPublicRecordsBundle:Record')->findAll();

        return array(
            'entities' => $entities,
        );
    }

    /**
     * Lists all Records by forms.
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @Route("/viewrecords/{formid}/form", requirements={"formid"="\d+"}, defaults={"formid"=0}, name="public_record_viewrecords")
     * @Method("GET")
     * @Template()
     */
    public function viewRecordsAction($formid)
    {
        $em = $this->getDoctrine()->getManager();
        $queryBuilder = $this->getDoctrine()->getManager()->createQueryBuilder();

        $userManager = $this->container->get('fos_user.user_manager');
        $user = $userManager->findUserByUsername($this->getUser());
        $organisationunit = $user->getOrganisationunit();

        if ($formid == 0) {
            $formIds = $this->getDoctrine()->getManager()->createQueryBuilder()->select('form.id')
                ->from('HrisFormBundle:Form', 'form')->getQuery()->getArrayResult();
            $formIds = $this->array_value_recursive('id', $formIds);
            $forms = $em->getRepository('HrisFormBundle:Form')->findAll();
        } else {
            $forms = $em->getRepository('HrisFormBundle:Form')->findby(array('id' => $formid));
            $formIds[] = $formid;
        }

        //Prepare field Option map, converting from stored FieldOption key in record value array to actual text value
        $fieldOptions = $this->getDoctrine()->getManager()->getRepository('HrisFormBundle:FieldOption')->findAll();
        foreach ($fieldOptions as $fieldOptionKey => $fieldOption) {
            $recordFieldOptionKey = ucfirst(Record::getFieldOptionKey());
            $fieldOptionMap[call_user_func_array(array($fieldOption, "get${recordFieldOptionKey}"), array())] = $fieldOption->getValue();
        }

        //If user's organisationunit is data entry level pull only records of his organisationunit
        //else pull lower children too.
        $records = $queryBuilder->select('record')
            ->from('HrisPublicRecordsBundle:Record', 'record')
            ->join('record.organisationunit', 'organisationunit')
            ->join('record.form', 'form');
        if ($organisationunit->getOrganisationunitStructure()->getLevel()->getDataentrylevel()) {
            $records = $records
                ->join('organisationunit.organisationunitStructure', 'organisationunitStructure')
                ->join('organisationunitStructure.level', 'organisationunitLevel')
                ->andWhere('organisationunitLevel.level >= (
                                        SELECT selectedOrganisationunitLevel.level
                                        FROM HrisOrganisationunitBundle:OrganisationunitStructure selectedOrganisationunitStructure
                                        INNER JOIN selectedOrganisationunitStructure.level selectedOrganisationunitLevel
                                        WHERE selectedOrganisationunitStructure.organisationunit=:selectedOrganisationunit )'
                )
                //->andWhere('organisationunitStructure.level'.$organisationunit->getOrganisationunitStructure()->getLevel()->getLevel().'Organisationunit=:levelId');
                ->andWhere('organisationunitStructure.organisationunit=:levelId');
            $parameters = array(
                'levelId' => $organisationunit->getId(),
                'selectedOrganisationunit' => $organisationunit->getId(),
                'formIds' => $formIds,
            );
        } else {
            $records = $records->andWhere('organisationunit.id=:selectedOrganisationunit');
            $parameters = array(
                'selectedOrganisationunit' => $organisationunit->getId(),
                'formIds' => $formIds,
            );
        }
        //var_dump($parameters);
        //echo "<br /><br /><br />";
        //echo $records->andWhere($queryBuilder->expr()->in('form.id',':formIds'))->setParameters($parameters)->getQuery()->getSQL();
        //exit();
        $records = $records->andWhere($queryBuilder->expr()->in('form.id', ':formIds'))
            ->setParameters($parameters)
            ->getQuery()->getResult();

        $formNames = NULL;
        $visibleFields = Array();
        $formFields = Array();
        $incr = 0;
        $formIds = Array();
        foreach ($forms as $formKey => $form) {
            $incr++;
            $formIds[] = $form->getId();
            // Concatenate form Names
            if (empty($formNames)) {
                $formNames = $form->getTitle();
            } else {
                if (count($formNames) == $incr) $formNames .= ',' . $form->getTitle();
            }
            // Accrue visible fields
            foreach ($form->getFormVisibleFields() as $visibleFieldKey => $visibleField) {

                if (!in_array($visibleField->getField(), $visibleFields)) $visibleFields[] = $visibleField->getField();
            }
            // Accrue form fields
            foreach ($form->getFormFieldMember() as $formFieldKey => $formField) {
                if (!in_array($formField->getField(), $formFields)) $formFields[] = $formField->getField();
            }
        }
        $title = "Employee Records for " . $organisationunit->getLongname();

        $title .= " for " . $formNames;
        if (empty($visibleFields)) $visibleFields = $formFields;

        //getting all User Forms for User Migration

        $user = $this->container->get('security.context')->getToken()->getUser();
        $userForms = $user->getForm();

        $delete_forms = NULL;
        foreach ($records as $entity) {
            $delete_form = $this->createDeleteForm($entity->getId());
            $delete_forms[$entity->getId()] = $delete_form->createView();
        }

        return array(
            'title' => $title,
            'visibleFields' => $visibleFields,
            'formFields' => $formFields,
            'records' => $records,
            'optionMap' => $fieldOptionMap,
            'userForms' => $userForms,
            'delete_forms' => $delete_forms,
            'formid' => $formid,
        );
    }


    /**
     * List Forms Available for Record entry.
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @Route("/formlist/dataentry", defaults={"channel"="dataentry"}, name="public_record_form_list")
     * @Route("/formlist/updaterecords", defaults={"channel"="updaterecords"}, name="public_record_form_list_updaterecords")
     * @Route("/formlist/updateleaverecords", defaults={"channel"="leaverecords"}, name="public_record_form_list_leaverecords")
     * @Method("GET")
     * @Template()
     */
    public function formlistAction($channel)
    {
        /*
         * Getting the Form Metadata and Values
         */
        $em = $this->getDoctrine()->getManager();
        $queryBuilder = $this->getDoctrine()->getManager()->createQueryBuilder();
        $entities = $queryBuilder->select('form')
            ->from('HrisFormBundle:Form', 'form')
            ->join('form.user', 'user')
            ->andWhere("user.username='" . $this->getUser() . "'")
            ->getQuery()->getArrayResult();

        return array(
            'entities' => $entities,
            'channel' => $channel,
            'message' => '',

        );
    }

    /**
     * List Forms Available for Update Record.
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @Route("/formlistupdate", name="public_record_form_list_update")
     * @Method("GET")
     * @Template("HrisPublicRecordsBundle:Record:formlistupdate.html.twig")
     */
    public function formlistupdateAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('HrisFormBundle:Form')->createQueryBuilder('p')->getQuery()->getArrayResult();

        return array(
            'entities' => $entities,
        );
    }

    /**
     * Creates a new Record entity.
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @Route("/", name="public_record_create")
     * @Method("POST")
     * @Template("HrisPublicRecordsBundle:Record:new.html.twig")
     */
    public function createAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = new Record();
        //$record = $this->createForm(new RecordType(), $entity);
        //$record->bind($request);
        $message = '';

        $formId = $this->getRequest()->get('formid');

        $user = $this->container->get('security.context')->getToken()->getUser();

        $onrgunitParent = $this->get('request')->request->get('orgunitParent');
        $orunitUid = $this->get('request')->request->get('selectedOrganisationunit');

        if ($orunitUid != null) {
            $orgunit = $em->getRepository('HrisOrganisationunitBundle:Organisationunit')->findOneBy(array('uid' => $orunitUid));
        } else {
            $orgunit = $user->getOrganisationunit();
        }

        $form = $em->getRepository('HrisFormBundle:Form')->find($formId);

        $uniqueFields = $form->getUniqueRecordFields();
        $fields = $form->getSimpleField();

        $instance = '';
        foreach ($uniqueFields as $key => $field_unique) {
            $instance .= $this->getRequest()->get($field_unique->getName());
            if ($field_unique->getDataType()->getName() != "Date") $message .= $this->getRequest()->get($field_unique->getName()) . " ";
        }


        foreach ($fields as $key => $field) {
            $recordValue = $this->get('request')->request->get($field->getName());

            if ($field->getDataType()->getName() == "Date" && $recordValue != null) {
                $recordValue = DateTime::createFromFormat('d/m/Y', $recordValue)->format('Y-m-d');
                $recordValue = new \DateTime($recordValue);
            }

            /**
             * Made dynamic, on which field column is used as key, i.e. uid, name or id.
             */
            // Translates to $field->getUid()
            // or $field->getUid() depending on value of $recordKeyName
            $recordFieldKey = ucfirst(Record::getFieldKey());
            $valueKey = call_user_func_array(array($field, "get${recordFieldKey}"), array());

            $recordArray[$valueKey] = $recordValue;
        }

        $entity->setValue($recordArray);
        $entity->setForm($form);
        $entity->setInstance(md5($instance));
        $entity->setOrganisationunit($orgunit);
        $entity->setUsername($user->getUsername());
        $entity->setComplete(True);
        $entity->setCorrect(True);
        $entity->setHashistory(False);
        $entity->setHastraining(False);


        //if ($entity->isValid()) {
        $em = $this->getDoctrine()->getManager();
        try {

            $em->persist($entity);
            $em->flush();
            $message .= "saved successfully";
            $success = 'true';
        } catch (DBALException $exception) {
            $record = $em->getRepository('HrisPublicRecordsBundle:Record')->findOneBy(array('instance' => $entity->getInstance()));
            $message .= " is existing for " . $entity->getOrganisationunit()->getLongname();
            $parent = $entity->getOrganisationunit()->getParent();
            if (!empty($parent)) $message .= " in " . $entity->getOrganisationunit()->getParent()->getLongname() . "!";
            $message .= ' <a href="' . $this->generateUrl('record_edit', array('id' => $record->getId(), 'message' => $message)) . '">Click here to edit existing record</a>';
            $success = 'false';
        }

        return $this->redirect($this->generateUrl('record_new', array('id' => $form->getId(), 'message' => $message, 'success' => $success)));

    }


    /**
     * Displays a form to create a new Record entity.
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @Route("/new/{id}", requirements={"id"="\d+"}, name="public_record_new")
     * @Method("GET")
     * @Template()
     */
    public function newAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $formEntity = $em->getRepository('HrisFormBundle:Form')->find($id);

        //$user = $this->container->get('security.context')->getToken()->getUser();

        // Workaround to send message when user is redirected from one data entry page to another.
        $message = $this->getRequest()->get('message');
        $success = $this->getRequest()->get('success');
        $organisationunitLevels = $this->getDoctrine()->getManager()->createQueryBuilder()
            ->select('organisationunitLevel')
            ->from('HrisOrganisationunitBundle:OrganisationunitLevel', 'organisationunitLevel')
            ->where('organisationunitLevel.level>' . '1')
            ->andWhere('organisationunitLevel.level>' . '1')
            ->orderBy('organisationunitLevel.level', 'ASC')
            ->orderBy('organisationunitLevel.name', 'ASC')
            ->getQuery()->getResult();
        $organisationunit = $this->getDoctrine()->getManager()->createQueryBuilder()
            ->select('organisationunit')
            ->from('HrisOrganisationunitBundle:organisationunit', 'organisationunit')
            ->where('organisationunit.longname =\'Regions\'')
            ->orderBy('organisationunit.longname', 'ASC')
            ->getQuery()->getResult();
        $isEntryLevel = true;//$organisationunit->getOrganisationunitStructure()->getLevel()->getDataentrylevel();

        return array(
            'formEntity' => $formEntity,
            'message' => $message,
            'success' => $success,
            'isEntryLevel' => $isEntryLevel,
            'organisationunit' => $organisationunit,
            'message' => $message,
            'organisationunitLevels' => $organisationunitLevels,
        );
    }

    /**
     * Finds and displays a Record entity.
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @Route("/{id}", requirements={"id"="\d+"}, name="public_record_show")
     * @Method("GET")
     * @Template()
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('HrisPublicRecordsBundle:Record')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Record entity.');
        }

        //Prepare field Option map, converting from stored FieldOption key in record value array to actual text value
        $fieldOptions = $this->getDoctrine()->getManager()->getRepository('HrisFormBundle:FieldOption')->findAll();
        foreach ($fieldOptions as $fieldOptionKey => $fieldOption) {
            $recordFieldOptionKey = ucfirst(Record::getFieldOptionKey());
            $fieldOptionMap[call_user_func_array(array($fieldOption, "get${recordFieldOptionKey}"), array())] = $fieldOption->getValue();
        }

        $deleteForm = $this->createDeleteForm($id);

        return array(
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
            'optionMap' => $fieldOptionMap,
        );
    }

    /**
     * Creates a form to delete a Record entity by id.
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder(array('id' => $id))
            ->add('id', 'hidden')
            ->getForm();
    }

    /**
     * Displays a form to edit an existing Record entity.
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @Route("/{id}/edit", requirements={"id"="\d+"}, name="public_record_edit")
     * @Method("GET")
     * @Template()
     */
    public function editAction($id)
    {
        $message = NULL;
        $em = $this->getDoctrine()->getManager();
        $entity = $em->getRepository('HrisPublicRecordsBundle:Record')->find($id);
        $formEntity = $entity->getForm();

        $user = $this->container->get('security.context')->getToken()->getUser();


        $isEntryLevel = $user->getOrganisationunit()->getOrganisationunitStructure()->getLevel()->getDataentrylevel();
        // Workaround to send message when user is redirected from one data entry page to another.
        $message = $this->getRequest()->get('message');
        $organisationunitLevels = $this->getDoctrine()->getManager()->createQueryBuilder()
            ->select('organisationunitLevel')
            ->from('HrisOrganisationunitBundle:organisationunitLevel', 'organisationunitLevel')
            ->where('organisationunitLevel.level>' . $user->getOrganisationunit()->getOrganisationunitStructure()->getLevel()->getLevel())
            ->andWhere('organisationunitLevel.level>' . $user->getOrganisationunit()->getOrganisationunitStructure()->getLevel()->getLevel())
            ->orderBy('organisationunitLevel.level', 'ASC')
            ->orderBy('organisationunitLevel.name', 'ASC')
            ->getQuery()->getResult();

        $organisationunits = $this->getDoctrine()->getManager()->createQuery("SELECT organisationunit
                                                        FROM HrisOrganisationunitBundle:Organisationunit organisationunit
                                                        WHERE organisationunit.parent=:parentid
                                                        GROUP BY organisationunit.id,organisationunit.longname
                                                        ORDER BY organisationunit.longname ASC")->setParameter('parentid', $user->getOrganisationunit()->getId())->getResult();

        return array(
            'formEntity' => $formEntity,
            'entryLevel' => $isEntryLevel,
            'entity' => $entity,
            'user' => $user,
            'message' => $message,
            'organisationunitLevels' => $organisationunitLevels,
            'organisationunits' => $organisationunits,
        );
    }

    /**
     * Edits an existing Record entity.
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @Route("/update", name="public_record_update")
     * @Method("POST")
     * @Template("HrisPublicRecordsBundle:Record:viewRecords.html.twig")
     */

    public function updateAction(Request $request)
    {

        $em = $this->getDoctrine()->getManager();

        $instance = $this->getRequest()->get('instance');

        $entity = $em->getRepository('HrisPublicRecordsBundle:Record')->findOneBy(array('instance' => $instance));

        $formId = (int)$this->getRequest()->get('formid');

        $user = $this->container->get('security.context')->getToken()->getUser();

        $onrgunitParent = $this->getRequest()->get('orgunitParent');
        $orunitUid = $this->getRequest()->get('selectedOrganisationunit');

        if ($orunitUid != null) {
            $orgunit = $em->getRepository('HrisOrganisationunitBundle:Organisationunit')->findOneBy(array('uid' => $orunitUid));
        } else {
            $orgunit = $user->getOrganisationunit();
        }

        $form = $em->getRepository('HrisFormBundle:Form')->find($formId);

        $uniqueFields = $form->getUniqueRecordFields();
        $fields = $form->getSimpleField();

        foreach ($fields as $key => $field) {
            $recordValue = $this->get('request')->request->get($field->getName());

            /*
            if($field->getDataType()->getName() == "Date"){
                $recordValue = new \DateTime($recordValue);
            }
            */

            if ($field->getDataType()->getName() == "Date" && $recordValue != null) {

                $recordValue = DateTime::createFromFormat('d/m/Y', $recordValue)->format('Y-m-d');
                $recordValue = new \DateTime($recordValue);

            }

            /**
             * Made dynamic, on which field column is used as key, i.e. uid, name or id.
             */
            $recordFieldKey = ucfirst(Record::getFieldKey());
            $valueKey = call_user_func_array(array($field, "get${recordFieldKey}"), array());

            $recordArray[$valueKey] = $recordValue;
        }

        $entity->setValue($recordArray);
        $entity->setForm($form);
        $entity->setInstance($instance);
        $entity->setOrganisationunit($orgunit);
        $entity->setUsername($user->getUsername());
        $entity->setComplete(True);
        $entity->setCorrect(True);
        $entity->setHashistory(False);
        $entity->setHastraining(False);


        //if ($entity->isValid()) {
        $em = $this->getDoctrine()->getManager();
        $em->persist($entity);
        $em->flush();

        return $this->redirect($this->generateUrl('record_viewrecords', array('formid' => $form->getId())));

    }

    /**
     * Check uniqueness of record
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @Route("/checkUniqueness/{_format}", requirements={"_format"="yml|xml|json"}, defaults={"_format"="json"}, name="public_record_checkuniqueness")
     * @Method("GET")
     * @Template()
     */
    public function checkUniquenessAction(Request $request, $_format)
    {
        $em = $this->getDoctrine()->getManager();


        $queryBuilder = $this->getDoctrine()->getManager()->createQueryBuilder();

        $recordsResults = $queryBuilder->select('record')->from('HrisPublicRecordsBundle:Record', 'record');

        $uniquenessVariables = $this->getRequest()->query->all();
        $incr = 0;
        foreach ($uniquenessVariables as $fieldUid => $fieldValue) {
            $valuePattern = '';
            unset($valuePattern);
            $incr++;
            if (!empty($fieldValue)) {
                $valuePattern[$fieldUid] = $fieldValue;
                $json = json_encode($valuePattern);
                $pattern = str_replace("{", "", $json);
                $pattern = str_replace("}", "", $pattern);
                if (!preg_match('[unique_]', $fieldUid)) {
                    // Takes care of individuallly unique fields
                    $recordsResults->orWhere("record.value LIKE '%$pattern%' ");

                } else {
                    // Takes care of collectively fields

                }
            }

        }
//
//        // Check instance uniqueness
//        $formId = $this->getRequest()->get('formid');
//
//        $form = $em->getRepository('HrisFormBundle:Form')->find($formId);
//
//        $uniqueFields = $form->getUniqueRecordFields();
//        $fields = $form->getSimpleField();
//
//        $instance = '';
//        foreach($uniqueFields as $key => $field_unique){
//            echo "name:".$field_unique->getName()."<br/>";
//            $instance .= $this->getRequest()->get('unique_'.$field_unique->getUid());
//        }
//        print_r($instance);
//        die();


        $output = $recordsResults->setMaxResults(1)->getQuery()->getResult();
        //@todo implement stating what's unique and what's not
        if (empty($output)) {
            $message = "true";
        } else {
            $message = "false";
        }
        return array(
            'message' => $message
        );

    }

    /**
     * Deletes a Record entity.
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @Route("/{id}", requirements={"id"="\d+"}, name="public_record_delete")
     * @Method("DELETE")
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->bind($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $entity = $em->getRepository('HrisPublicRecordsBundle:Record')->find($id);
            $form = $entity->getForm();

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Record entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('record_viewrecords', array('formid' => $form->getId())));
    }

    /**
     * Get all values from specific key in a multidimensional array
     *
     * @param $key string
     * @param $arr array
     * @return null|string|array
     */
    public function array_value_recursive($key, array $arr)
    {
        $val = array();
        array_walk_recursive($arr, function ($v, $k) use ($key, &$val) {
            if ($k == $key) array_push($val, $v);
        });
        return count($val) > 1 ? $val : array_pop($val);
    }

    /**
     * Change the Forms for the Employee.
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @Route("/changeform", name="public_record_form_change")
     * @Method("POST")
     */

    public function updateFormAction(Request $request)
    {

        $em = $this->getDoctrine()->getManager();

        $uid = $this->get('request')->request->get('record_uid');

        $formId = $this->get('request')->request->get('form_id');

        $entity = $em->getRepository('HrisPublicRecordsBundle:Record')->findOneBy(array('uid' => $uid));

        $form = $em->getRepository('HrisFormBundle:Form')->find($formId);

        $entity->setForm($form);

        $em = $this->getDoctrine()->getManager();
        $em->persist($entity);
        $em->flush();

        return new Response('success');

    }

    /**
     * Search Record Checklist number
     *
     * @Secure(roles="IS_AUTHENTICATED_ANONYMOUSLY")
     * @Route("/searchCheckList/{checkNumber}",defaults={"checkNumber" = null}, name="public_search_checklist")
     * @Method("GET")
     * @return null/string/array
     */
    public function searchCheckList($checkNumber)
    {
        $entityManager = $this->getDoctrine()->getManager();

        $response = array();

        try {

            if ($checkNumber === NULL) {
                $response = array('error' => "No checklist number supplied");
            } else {

                $query = "SELECT R.firstname, R.middlename, R.surname, R.designation,R.dob, R.sex, R.edu_evel, R.check_no, R.department, R.employment_status, R.level5_facility ,R.retirementdistribution ";
                $query .= "FROM _resource_all_fields R ";
                $query .= "INNER JOIN hris_record  on hris_record.instance = R.instance ";
                $query .= "WHERE R.check_no = " . $checkNumber;
                $query .= " ORDER BY R.firstname ASC";

                $statement = $entityManager->getConnection()->prepare($query);
                if ($statement->execute()){
                    $response = $statement->fetchAll();
                } else {
                    throw new Exception("Invalid check number") ;
                }


            }

        } catch (Exception $E) {

            $response = array('error' => "No valid checklist number supplied");
        }


        return new JsonResponse($response);
    }
}


