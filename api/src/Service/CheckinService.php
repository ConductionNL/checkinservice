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

    public function sendEmail($webHook, $checkin, $emailType)
    {
        $content = [];
        switch ($emailType) {
            case 'welkom':
                $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-welkom"])['@id'];
                break;
        }
        if ($organization = $this->commonGroundService->isResource($checkin['node']['organization'])) {
            if (key_exists('contact', $organization)) {
                $receiver = $organization['contact'];
            } else {
                return 'Geen ontvanger gevonden [organization van de node heeft geen contact]';
            }
        } else {
            return 'Geen ontvanger gevonden [organization van de node is geen resource]';
        }
        $message = $this->createMessage($checkin, $content, $receiver);

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

                $percentage = round($numberOfCheckins / $maximumAttendeeCapacity * 100,1,PHP_ROUND_HALF_UP);

                $results['numberOfCheckins'] = $numberOfCheckins;
                $results['maximumAttendeeCapacity'] = $maximumAttendeeCapacity;
                $results['percentage'] = $percentage;
            } else {
                return 'De accommodation van de node heeft geen maximumAttendeeCapacity';
            }
        } else {
            return 'De accommodation van de node is geen resource';
        }

        return $results;
    }

    public function createMessage(array $checkin, $content, $receiver, $attachments = null)
    {
        $application = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}"]);
        if (key_exists('@id', $application['organization'])) {
            $serviceOrganization = $application['organization']['@id'];
        } else {
            $serviceOrganization = $checkin['organization'];
        }

        $message = [];
        $message['service'] = $this->commonGroundService->getResourceList(['component'=>'bs', 'type'=>'services'], "type=mailer&organization=$serviceOrganization")['hydra:member'][0]['@id'];
        $message['status'] = 'queued';
        $organization = $this->commonGroundService->getResource($checkin['organization']);

        if ($organization['contact']) {
            $message['sender'] = $organization['contact'];
        }
        $message['reciever'] = $receiver;
        if (!key_exists('sender', $message)) {
            $message['sender'] = $receiver;
        }

        $message['data'] = ['resource'=>$checkin, 'sender'=>$organization, 'receiver'=>$this->commonGroundService->getResource($message['reciever'])];
        $message['content'] = $content;
        if ($attachments) {
            $message['attachments'] = $attachments;
        }

        return $message;
    }
}
