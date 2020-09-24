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
        $results[0] = 'CHECKIN'; //remove me later!
        $request = $this->commonGroundService->getResource($webHook->getRequest());

//        switch ($request['status']) {
//            case 'submitted':
//                array_push($results, $this->createUser($webHook, $request));
//                array_push($results, $this->sendEmail($webHook, $request, 'welkom'));
//                break;
//            case 'cancelled':
//                array_push($results, $this->sendEmail($webHook, $request, 'annulering'));
//                break;
//        }
        $webHook->setResult($results);
        $this->em->persist($webHook);
        $this->em->flush();

        return $webHook;
    }

    public function sendEmail($webHook, $request, $emailType)
    {
        $content = [];
        switch ($emailType) {
            case 'welkom':
                $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-welkom"])['@id'];
                break;
            case 'inlognaam':
                $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-inlognaam"])['@id'];
                break;
            case 'wachtwoord':
                $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-wachtwoord"])['@id'];
                break;
            case 'annulering':
                $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-annulering"])['@id'];
                break;
        }
        if (key_exists('contact_gegevens', $request['properties'])) {
            $receiver = $request['properties']['contact_gegevens'];
        } else {
            return 'Geen ontvanger gevonden';
        }
        $message = $this->createMessage($request, $content, $receiver);

        return $this->commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages'])['@id'];
    }

    public function createUser($webHook, $request)
    {
        // Get contact for the new user and get the email for this users username
        $username = 'undefined@contact.nl';
        if (key_exists('contact_gegevens', $request['properties'])) {
            if ($person = $this->commonGroundService->isResource($request['properties']['contact_gegevens'])) {
                if (key_exists('emails', $person)) {
                    $username = $person['emails'][0]['email'];
                }
            }
        } else {
            return 'contact_gegevens does not exist in this request';
        }

        // Create an Organization in WRC, a Place in LC and a Node in CHIN
        $organization = [];
        if (key_exists('horeca_onderneming_contact', $request['properties'])) {
            if ($organizationContact = $this->commonGroundService->isResource($request['properties']['horeca_onderneming_contact'])) {

                //Create an Organization
                $organization['name'] = $organizationContact['name'];
                $organization['description'] = $organizationContact['description'];
                if (defined($organizationContact['kvk']) and (!empty($organizationContact['kvk']))) {
                    $organization['chamberOfComerce'] = $organizationContact['kvk'];
                } elseif (key_exists('kvk', $request['properties'])) {
                    $organization['chamberOfComerce'] = $request['properties']['kvk'];
                } else {
                    $organization['chamberOfComerce'] = '';
                }
                $organization['rsin'] = '';
                $organization = $this->commonGroundService->saveResource($organization, ['component' => 'wrc', 'type' => 'organizations']);

                // Create an Organization Logo
                $logo['name'] = $organizationContact['name'].' Logo';
                $logo['description'] = $organizationContact['name'].' Logo';
                $logo['organization'] = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);
                $this->commonGroundService->saveResource($logo, ['component' => 'wrc', 'type' => 'images']);

                // Create an Organization Favicon
                $favicon['name'] = 'favicon';
                $favicon['description'] = $organizationContact['name'].' favicon';
                $favicon['organization'] = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);
                $favicon = $this->commonGroundService->saveResource($favicon, ['component' => 'wrc', 'type' => 'images']);

                // Create an Organization Style
                $style['name'] = $organizationContact['name'];
                $style['description'] = 'Huisstijl '.$organizationContact['name'];
                $style['css'] = '';
                $style['favicon'] = $favicon['@id'];
                $style['organization'] = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);
                $this->commonGroundService->saveResource($style, ['component' => 'wrc', 'type' => 'styles']);

                // Create a Place
                $place['name'] = $organizationContact['name'];
                $place['description'] = $organizationContact['description'];
                $place['publicAccess'] = true;
                $place['smokingAllowed'] = false;
                $place['openingTime'] = '16:00';
                $place['closingTime'] = '1:00';
                $place['organization'] = $organization['@id'];
                $place = $this->commonGroundService->saveResource($place, ['component' => 'lc', 'type' => 'places']);

                // Create a (example) Place Accommodation
                $accommodation['name'] = 'Tafel 1';
                $accommodation['description'] = $organizationContact['name'].' Tafel 1';
                $accommodation['place'] = $place['@id'];
                $this->commonGroundService->saveResource($accommodation, ['component' => 'lc', 'type' => 'accommodations']);

                // Create a Node
                $node['name'] = 'Tafel 1';
                $node['description'] = $organizationContact['name'].' Tafel 1';
                $node['passthroughUrl'] = 'https://zuid-drecht.nl';
                $node['place'] = $place['@id'];
                $node['organization'] = $organization['@id'];
                $this->commonGroundService->saveResource($node, ['component' => 'chin', 'type' => 'nodes']);
            } else {
                return 'horeca_onderneming_contact is not a resource';
            }
        } else {
            return 'horeca_onderneming_contact does not exist in this request';
        }

        // Create an user in UC
        $user['organization'] = $organization['@id'];
        $user['username'] = $username;
        $user['password'] = 'test1234';
        $user['person'] = $person['@id'];
        $user['userGroups'] = [
            $this->commonGroundService->cleanUrl(['component' => 'uc', 'type' => 'groups', 'id' => '4085d475-063b-47ed-98eb-0a7d8b01f3b7']),
        ];
        $this->commonGroundService->saveResource($user, ['component' => 'uc', 'type' => 'users']);

        array_push($results, $this->sendEmail($webHook, $request, 'inlognaam'));
        array_push($results, $this->sendEmail($webHook, $request, 'wachtwoord'));

        return $results;
    }

    public function createMessage(array $request, $content, $receiver, $attachments = null)
    {
        $application = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}"]);
        if (key_exists('@id', $application['organization'])) {
            $serviceOrganization = $application['organization']['@id'];
        } else {
            $serviceOrganization = $request['organization'];
        }

        $message = [];
        $message['service'] = $this->commonGroundService->getResourceList(['component'=>'bs', 'type'=>'services'], "type=mailer&organization=$serviceOrganization")['hydra:member'][0]['@id'];
        $message['status'] = 'queued';
        $organization = $this->commonGroundService->getResource($request['organization']);

        if ($organization['contact']) {
            $message['sender'] = $organization['contact'];
        }
        $message['reciever'] = $receiver;
        if (!key_exists('sender', $message)) {
            $message['sender'] = $receiver;
        }

        $message['data'] = ['resource'=>$request, 'sender'=>$organization, 'receiver'=>$this->commonGroundService->getResource($message['reciever'])];
        $message['content'] = $content;
        if ($attachments) {
            $message['attachments'] = $attachments;
        }

        return $message;
    }
}
