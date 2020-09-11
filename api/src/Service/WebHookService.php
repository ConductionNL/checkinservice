<?php

namespace App\Service;

use App\Entity\WebHook;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use DateInterval;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class WebHookService
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

    public function webHook(WebHook $webHook)
    {
        if ($webHook->getTask() && $task = $this->commonGroundService->getResource($webHook->getTask())) {
            $this->executeTask($task, $webHook);
        } else {
            $resource = $this->commonGroundService->getResource($webHook->getResource());
            $results = [];
            $results = array_merge($results, $this->checkRequestStatus($webHook, $resource));
            $webHook->setResult($results);
        }
        $this->em->persist($webHook);
        $this->em->flush();
    }

    public function checkRequestStatus(WebHook $webHook, $resource)
    {
        $messages = [];
        if($resource['status'] == 'submitted') {
            // Get email message content
            $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-indiening"])['@id'];

            // Create email message
            $messages = $this->createMessages($content, $resource);

            // Create a user in UC
//            $user['organization'] = ...;
//            $user['username'] = ...;
//            $user['password'] = ...;
//            $user['person'] = ...;
//            $user['userGroups'][0] = $this->commonGroundService->cleanUrl(['component' => 'uc', 'type' => 'groups'], ['id' => '4085d475-063b-47ed-98eb-0a7d8b01f3b7']);
//            $this->commonGroundService->createResource($user, ['component' => 'uc', 'type' => 'users']);
        } elseif ($resource['status'] == 'cancelled') {
            // Get email message content
            $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-annulering"])['@id'];

            // Create email message
            $messages = $this->createMessages($content, $resource);
        }

        // Send email
        $result = [];
        foreach($messages as $message){
            $result[] = $this->commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages'])['@id'];
        }

        return $result;
    }

    public function createMessages($content, $resource){
        $messages = [];
        $message['service'] = $this->commonGroundService->getResourceList(['component'=>'bs', 'type'=>'services'], "?type=mailer&organization={$resource['organization']}")['hydra:member'][0]['@id'];
        $message['status'] = 'queued';
        $organization = $this->commonGroundService->getResource($resource['organization']);

        if ($organization['contact']) {
            $message['sender'] = $organization['contact'];
        }
        $submitters = $resource['submitters'];
        $message['content'] = $content;
        foreach ($submitters as $submitter) {
            if (key_exists('person', $submitter) && $submitter['person'] != null) {
                $message['reciever'] = $this->commonGroundService->getResource($submitter['person']);
                if (!key_exists('sender', $message)) {
                    $message['sender'] = $message['reciever'];
                }
                $message['data'] = ['resource'=>$resource, 'contact'=>$message['reciever'], 'organization'=>$message['sender']];
                $messages[] = $message;
            }
        }

        if (key_exists('contact_gegevens', $resource['properties'])) {
            if ($message['reciever'] = $this->commonGroundService->isResource($resource['properties']['contact_gegevens'])) {
                if (!key_exists('sender', $message)) {
                    $message['sender'] = $message['reciever'];
                }
                $message['data'] = ['resource'=>$resource, 'sender'=>$organization, 'receiver'=>$this->commonGroundService->getResource($message['reciever'])];
                $messages[] = $message;
            }
        }
        return $messages;
    }
}
