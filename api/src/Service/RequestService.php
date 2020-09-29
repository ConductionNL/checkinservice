<?php

namespace App\Service;

use App\Entity\WebHook;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class RequestService
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
        $request = $this->commonGroundService->getResource($webHook->getRequest());

        switch ($request['status']) {
            case 'submitted':
                array_push($results, $this->processRequest($webHook, $request));
                //array_push($results, $this->sendEmail($webHook, $request, 'welkom'));
                break;
            case 'cancelled':
                //array_push($results, $this->sendEmail($webHook, $request, 'annulering'));
                break;
        }
        $webHook->setResult($results);
        $this->em->persist($webHook);
        $this->em->flush();

        return $webHook;
    }

    public function sendEmail($webHook, $request, $data,  $emailType)
    {
        $content = [];
        switch ($emailType) {
            case 'welkom':
                $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'templates', 'id'=>"2ca5b662-e941-46c9-ae87-ae0c68d0aa5d"]);
                break;
            case 'password':
                $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'templates', 'id'=>"07075add-89c7-4911-b255-9392bae724b3"]);
                break;
            case 'annulering':
                $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'templates', 'id'=>"4016c529-cf9e-415e-abb1-2aba8bfa539e"]);
                break;
        }

        // determining the reciever
        if (key_exists('organization', $request['properties'])) {
            if ($organizationContact = $this->commonGroundService->isResource($request['properties']['organization'])) {
                if (key_exists('emails', $organizationContact) and (count($organizationContact['emails']) > 0)) {
                    $receiver = $organizationContact['@id'];
                } elseif (key_exists('persons', $organizationContact) and (count($organizationContact['persons']) > 0)) {
                    $receiver = $organizationContact['persons'][0]['@id'];
                } else {
                    return 'No email receiver found [organization does not have a email or contact person]';
                }
            } else {
                return 'No email receiver found [organization is not a resource]';
            }
        } else {
            return 'No email receiver found [organization does not exist]';
        }

        // Loading the message
        $message = $this->createMessage($data, $request, $content, $receiver);

        return $this->commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages'])['@id'];
    }

    public function processRequest($webHook, $request)
    {
        $results = [];
        // Get contact person and email for the username
        // Create an Organization in WRC, a Place in LC and a Node in CHIN
        // Create a user and send username & password emails
        if (key_exists('organization', $request['properties'])) {
            if ($organizationContact = $this->commonGroundService->isResource($request['properties']['organization'])) {

                $request['status'] = 'inProgress';
                $request =  $this->commonGroundService->saveResource($request);

                $acountData= [];
                $organization = [];

                // Get contact for the new user
                if (key_exists('persons', $organizationContact) and (count($organizationContact['persons']) > 0)) {
                    $person = $this->commonGroundService->getResource($this->commonGroundService->cleanUrl(['component' => 'cc', 'type' => 'people', 'id' => $organizationContact['persons'][0]['id']]));
                } else {
                    return 'organization does not have a contact person';
                }

                // Get the email for this users username
                if (key_exists('emails', $organizationContact) and (count($organizationContact['emails']) > 0)) {
                    $username = $organizationContact['emails'][0]['email'];
                } elseif (key_exists('emails', $person) and (count($person['emails']) > 0)) {
                    $username = $person['emails'][0]['email'];
                } else {
                    return 'organization and the contact person do not not have an email';
                }

                //Create an Organization
                $organization['name'] = $organizationContact['name'];
                $organization['description'] = $organizationContact['description'];
                if (key_exists('kvk', $organizationContact) and (!empty($organizationContact['kvk']))) {
                    $organization['chamberOfComerce'] = $organizationContact['kvk'];
                } elseif (key_exists('kvk', $request['properties'])) {
                    $organization['chamberOfComerce'] = $request['properties']['kvk'];
                } else {
                    $organization['chamberOfComerce'] = '';
                }
                $organization['rsin'] = '';
                $organization = $this->commonGroundService->saveResource($organization, ['component' => 'wrc', 'type' => 'organizations']);
                $acountData['organization'] = $organization;

                // Create an Organization Logo
                $logo['name'] = $organizationContact['name'].' Logo';
                $logo['description'] = $organizationContact['name'].' Logo';
                $logo['organization'] = '/organizations/'.$organization['id'];
                $this->commonGroundService->saveResource($logo, ['component' => 'wrc', 'type' => 'images']);
                $acountData['logo'] = $logo;

                // Create an Organization Favicon
                $favicon['name'] = 'favicon';
                $favicon['description'] = $organizationContact['name'].' favicon';
                $favicon['organization'] = '/organizations/'.$organization['id'];
                $favicon = $this->commonGroundService->saveResource($favicon, ['component' => 'wrc', 'type' => 'images']);
                $acountData['favicon'] = $favicon;

                // Create an Organization Style
                $style['name'] = $organizationContact['name'];
                $style['description'] = 'Huisstijl '.$organizationContact['name'];
                $style['css'] = '';
                $style['favicon'] = '/images/'.$favicon['id'];
                $style['organization'] = '/organizations/'.$organization['id'];
                $this->commonGroundService->saveResource($style, ['component' => 'wrc', 'type' => 'styles']);
                $acountData['style'] = $style;

                // Create a Place
                $place['name'] = $organizationContact['name'];
                $place['description'] = $organizationContact['description'];
                $place['publicAccess'] = true;
                $place['smokingAllowed'] = false;
                $place['openingTime'] = '09:00';
                $place['closingTime'] = '22:00';
                $place['organization'] = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);
                $place = $this->commonGroundService->saveResource($place, ['component' => 'lc', 'type' => 'places']);
                $acountData['place'] = $place;

                // Create a (example) Place Accommodation
                $accommodation['name'] = 'Tafel 1';
                $accommodation['description'] = $organizationContact['name'].' Tafel 1';
                $accommodation['place'] = '/places/'.$place['id'];
                $accommodation = $this->commonGroundService->saveResource($accommodation, ['component' => 'lc', 'type' => 'accommodations']);
                $acountData['accommodation'] = $accommodation;

                // Create a Node
                $node['name'] = 'Tafel 1';
                $node['description'] = $organizationContact['name'].' Tafel 1';
                $node['passthroughUrl'] = 'https://zuid-drecht.nl';
                $node['accommodation'] = $this->commonGroundService->cleanUrl(['component' => 'lc', 'type' => 'accommodations', 'id' => $accommodation['id']]);
                $node['organization'] = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);
                $node = $this->commonGroundService->saveResource($node, ['component' => 'chin', 'type' => 'nodes']);
                $acountData['node'] = $node;

                // Lets create a password
                $password = bin2hex(openssl_random_pseudo_bytes(4));

                // Create an user in UC
                $user['organization'] = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);
                $user['username'] = $username;
                $user['password'] = $password;
                $user['person'] = $this->commonGroundService->cleanUrl(['component' => 'cc', 'type' => 'people', 'id' => $person['id']]);
                $user['userGroups'] = [
                    '/groups/4085d475-063b-47ed-98eb-0a7d8b01f3b7',
                ];
                $user = $this->commonGroundService->saveResource($user, ['component' => 'uc', 'type' => 'users']);
                $acountData['user'] = $user;
                $user['password'] = $password;

                //Send username & password emails
                $request['status'] = 'processed';
                $request =  $this->commonGroundService->saveResource($request);

                array_push($results, $this->sendEmail($webHook, $request, $acountData, 'welkom'));
                array_push($results, $this->sendEmail($webHook, $request, $user, 'password'));
            } else {
                return 'organization is not a resource';
            }
        } else {
            return 'organization does not exist in this request';
        }

        return $results;
    }

    public function createMessage(array $data, array $request, $content, $receiver, $attachments = null)
    {
        $application = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}"]);
        if (key_exists('@id', $application['organization'])) {
            $serviceOrganization = $application['organization']['@id'];
        } else {
            $serviceOrganization = $request['organization'];
        }

        $message = [];

        // Tijdelijke oplossing voor juiste $message['service'] meegeven, was eerst dit hier onder, waar in de query op de organization check het mis gaat:
        //$message['service'] = $this->commonGroundService->getResourceList(['component'=>'bs', 'type'=>'services'], "type=mailer&organization=$serviceOrganization")['hydra:member'][0]['@id'];

        $message['service'] = '/services/1541d15b-7de3-4a1a-a437-80079e4a14e0';
        $message['status'] = 'queued';

        $organization = $this->commonGroundService->getResource($request['organization']);
        // lets use the organization as sender
        if ($organization['contact']) {
            $message['sender'] = $organization['contact'];
        }
        // if we don't have that we are going to self send te message
        $message['reciever'] = $receiver;
        if (!key_exists('sender', $message)) {
            $message['sender'] = $receiver;
        }

        $message['data'] = ['resource'=>$request, 'sender'=>$organization, 'receiver'=>$this->commonGroundService->getResource($message['reciever'])];
        $message['data'] = array_merge($message['data'], $data);  // lets accept contextual data from de bl
        $message['content'] = $content;
        if ($attachments) {
            $message['attachments'] = $attachments;
        }

        return $message;
    }
}
