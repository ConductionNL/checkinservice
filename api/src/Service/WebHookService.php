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
            $results = array_merge($results, $this->sendConfirmation($webHook, $resource));
            $webHook->setResult($results);
        }
        $this->em->persist($webHook);
        $this->em->flush();
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

        if (key_exists('partners', $resource['properties'])) {
            foreach ($resource['properties']['partners'] as $partner) {
                if ($partner = $this->commonGroundService->isResource($partner)) {
                    $message['reciever'] = $partner['contact'];
                    if (!key_exists('sender', $message)) {
                        $message['sender'] = $message['reciever'];
                    }
                    $message['data'] = ['resource'=>$resource, 'sender'=>$organization, 'receiver'=>$this->commonGroundService->getResource($message['reciever'])];
                    $messages[] = $message;
                }
            }
        }
        return $messages;
    }
    public function sendConfirmation(WebHook $webHook, $resource)
    {
        $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-indiening"])['@id'];
        $messages = $this->createMessages($content, $resource);

        $result = [];
        foreach($messages as $message){
            $result[] = $this->commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages'])['@id'];
        }

        return $result;
    }

    // Task execution from here
    public function executeTask(array $task, Webhook $webHook)
    {
        $resource = $this->commonGroundService->getResource($webHook->getResource());

        switch ($task['code']) {
            case 'update':
                $resource = $this->update($task, $resource);
                break;
            // (Voorbeeld)
//            case 'ingediend_onboarding':
//                $resource = $this->ingediendOnboarding($task, $resource);
//                break;
            default:
                break;
        }

        $this->commonGroundService->saveResource($resource);
    }

    public function update(array $task, array $resource)
    {
        // We want to force product shizle (Voorbeeld trouwservice)
//        $ceremonieOfferId = $this->commonGroundService->getUuidFromUrl($resource['properties']['plechtigheid']);
//        switch ($ceremonieOfferId) {
//            case '1ba1772b-cc8a-4808-ad1e-f9b3c93bdebf': // Flits huwelijks
//                $resource['properties']['ambtenaar'] = $this->commonGroundService->cleanUrl(['component'=>'pdc', 'type'=>'offers', 'id'=>'55af09c8-361b-418a-af87-df8f8827984b']);
//                $resource['properties']['locatie'] = $this->commonGroundService->cleanUrl(['component'=>'pdc', 'type'=>'offers', 'id'=>'9aef22c4-0c35-4615-ab0e-251585442b55']);
//                break;
//            case 'bfeb9399-fce6-49b8-a047-70928f3611fb': // Uitgebreid trouwen
//                // In het geval van uitgebreid trouwen hoeven we niks te forceren
//                break;
//        }

        //ingediend onboarding (Voorbeeld)
//        $newTask = [];
//        $newTask['code'] = 'ingediend_onboarding';
//        $newTask['resource'] = $resource['@id'];
//        $newTask['endpoint'] = $task['endpoint'];
//        $newTask['type'] = 'POST';
//
//        // Lets set the time to trigger
//        $dateToTrigger = new \DateTime();
//        $dateToTrigger->add(new \DateInterval('P2W')); // verloopt over 2 weken
//        $newTask['dateToTrigger'] = $dateToTrigger->format('Y-m-d H:i:s');
//        $this->commonGroundService->saveResource($newTask, ['component'=>'qc', 'type'=>'tasks']);

        return $resource;
    }

    // (Voorbeeld)
//    public function ingediendOnboarding(array $task, array $resource)
//    {
//        // valideren of het moet gebeuren
//        if (
//            $resource['status'] != 'incomplete' ||
//            $resource['status'] != 'cancelled'
//        ) {
//            return; // Eigenlijk moet je hier een error gooien maar goed
//        }
//
//        $resource['properties']['datum'] == null;
//
//        return $resource;
//    }
}
