<?php

namespace App\Service;

use App\Entity\WebHook;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class CheckinService
{
    private $em;
    private $commonGroundService;
    private $params;

    public function __construct(EntityManagerInterface $em, CommonGroundService $commonGroundService, ParameterBagInterface $params)
    {
        $this->em = $em;
        $this->commonGroundService = $commonGroundService;
        $this->params = $params;
    }

    public function handle(WebHook $webHook)
    {
        $results = [];
        $checkin = $this->commonGroundService->getResource($webHook->getRequest());

        array_push($results, $this->processCheckin($webHook, $checkin));

        $webHook->setResult($results);
        $this->em->persist($webHook);
        $this->em->flush();

        return $webHook;
    }

    public function sendEmail($webHook, $checkin, $data, $emailType)
    {
        $content = [];
        switch ($emailType) {
            case 'highCheckinCount':
                $content = $this->commonGroundService->cleanUrl(['component'=>'wrc', 'type'=>'templates', 'id'=>'cdad2591-c288-4f54-8fe5-727f67e65949']);
                break;
            case 'maxCheckinCount':
                $content = $this->commonGroundService->cleanUrl(['component'=>'wrc', 'type'=>'templates', 'id'=>'f073f5b5-2853-4cca-8de4-9889f21aa6a2']);
                break;
        }

        // determining the receiver
        if ($organization = $this->commonGroundService->isResource($checkin['node']['organization'])) {
            if (key_exists('contact', $organization)) {
                if ($organizationContact = $this->commonGroundService->isResource($organization['contact'])) {
                    if (key_exists('emails', $organizationContact) and (count($organizationContact['emails']) > 0)) {
                        $receiver = $organizationContact['@id'];
                    } elseif (key_exists('persons', $organizationContact) and (count($organizationContact['persons']) > 0)) {
                        // Could be a for loop to check if any contact person has an email:
                        $organizationContactPerson = $this->commonGroundService->getResource($organizationContact['persons'][0]['@id']);
                        if (key_exists('emails', $organizationContactPerson) and (count($organizationContactPerson['emails']) > 0)) {
                            $receiver = $organizationContactPerson['@id'];
                        } else {
                            return 'No email receiver found [organization contact and the organization contact person of the node do not have an email]';
                        }
                    } else {
                        return 'No email receiver found [organization contact of the node does not have an email or contact person]';
                    }
                } else {
                    return 'No email receiver found [organization contact of the node is no resource]';
                }
            } else {
                return 'No email receiver found [organization of the node has no contact]';
            }
        } else {
            return 'No email receiver found [organization of the node is no resource]';
        }
        $message = $this->createMessage($data, $checkin, $content, $receiver, $emailType);

        return $this->commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages'])['@id'];
    }

    public function processCheckin($webHook, $checkin)
    {
        $results = [];
        $node = $checkin['node'];

        if ($accommodation = $this->commonGroundService->isResource($node['accommodation'])) {
            if (key_exists('maximumAttendeeCapacity', $accommodation)) {
                $numberOfCheckins = count($this->commonGroundService->getResourceList(['component'=>'chin', 'type'=>'checkins'], ['node.accommodation'=>$node['accommodation']])['hydra:member']);
                $maximumAttendeeCapacity = $accommodation['maximumAttendeeCapacity'];

                // Calculate what percentage of the maximum attendees in checkins there is
                $percentage = round($numberOfCheckins / $maximumAttendeeCapacity * 100, 1, PHP_ROUND_HALF_UP);

                $results['numberOfCheckins'] = $numberOfCheckins;
                $results['maximumAttendeeCapacity'] = $maximumAttendeeCapacity;
                $results['percentage'] = $percentage;

                // Check if the number of active checkins (almost) exceeds the maximum number of attendees and if so, send a email.
                $accommodationData['accommodation'] = $accommodation;
                $accommodationData['numberOfCheckins'] = $numberOfCheckins;
                $nodeUrl = $this->commonGroundService->cleanUrl(['component'=>'chin', 'type'=>'node', 'id'=>$checkin['node']['id']]);
                if ($percentage >= 100) {
                    array_push($results, '100% or more');
                    $messages = $this->commonGroundService->getResourceList(['component'=>'bs', 'type'=>'messages'], ['resource'=>$nodeUrl, 'type'=>'maxCheckinCount'])['hydra:member'];
                    if (count($messages) < 1) {
                        array_push($results, $this->sendEmail($webHook, $checkin, $accommodationData, 'maxCheckinCount'));
                    } else {
                        array_push($results, 'Email has already been sent');
                    }
                } elseif ($percentage >= 80) {
                    array_push($results, '80% or more');
                    $messages = $this->commonGroundService->getResourceList(['component'=>'bs', 'type'=>'messages'], ['resource'=>$nodeUrl, 'type'=>'highCheckinCount'])['hydra:member'];
                    if (count($messages) < 1) {
                        array_push($results, $this->sendEmail($webHook, $checkin, $accommodationData, 'highCheckinCount'));
                    } else {
                        array_push($results, 'Email has already been sent');
                    }
                }
            } else {
                return 'De accommodation van de node heeft geen maximumAttendeeCapacity';
            }
        } else {
            return 'De accommodation van de node is geen resource';
        }

        return $results;
    }

    public function createMessage(array $data, array $checkin, $content, $receiver, $emailType, $attachments = null)
    {
        $application = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}"]);
        if (key_exists('@id', $application['organization'])) {
            $serviceOrganization = $application['organization']['@id'];
        }

        $message = [];

        // Tijdelijke oplossing voor juiste $message['service'] meegeven, was eerst dit hier onder, waar in de query op de organization check het mis gaat:
        //$message['service'] = $this->commonGroundService->getResourceList(['component'=>'bs', 'type'=>'services'], "type=mailer&organization=$serviceOrganization")['hydra:member'][0]['@id'];

        $message['service'] = '/services/1541d15b-7de3-4a1a-a437-80079e4a14e0';
        $message['status'] = 'queued';

        $organization = $this->commonGroundService->getResource($serviceOrganization);
        // lets use the organization as sender
        if ($organization['contact']) {
            $message['sender'] = $organization['contact'];
        }

        // if we don't have that we are going to self send te message
        $message['reciever'] = $receiver;
        if (!key_exists('sender', $message)) {
            $message['sender'] = $receiver;
        }

        $message['data'] = ['resource'=>$checkin, 'sender'=>$organization, 'receiver'=>$this->commonGroundService->getResource($message['reciever'])];
        $message['data'] = array_merge($message['data'], $data); // lets accept contextual data from de bl
        $message['content'] = $content;
        if ($attachments) {
            $message['attachments'] = $attachments;
        }

        $message['resource'] = $this->commonGroundService->cleanUrl(['component'=>'chin', 'type'=>'node', 'id'=>$checkin['node']['id']]);
        $message['type'] = $emailType;

        return $message;
    }
}
