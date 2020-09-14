<?php

namespace App\Subscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use App\Entity\Component;
use App\Entity\WebHook;
use App\Service\WebHookService;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\SerializerInterface;

class WebHookSubscriber implements EventSubscriberInterface
{
    private $params;
    private $em;
    private $webHookService;
    private $serializer;
    private $commonGroundService;

    public function __construct(ParameterBagInterface $params, EntityManagerInterface $em, SerializerInterface $serializer, WebHookService $webHookService, CommongroundService $commonGroundService)
    {
        $this->params = $params;
        $this->webHookService = $webHookService;
        $this->commonGroundService = $commonGroundService;
        $this->serializer = $serializer;
        $this->em = $em;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::VIEW => ['webHook', EventPriorities::PRE_SERIALIZE],
        ];
    }

    public function webHook(ViewEvent $event)
    {
        $method = $event->getRequest()->getMethod();
        $contentType = $event->getRequest()->headers->get('accept');
        $route = $event->getRequest()->attributes->get('_route');
        $resource = $event->getControllerResult();

        if (!$contentType) {
            $contentType = $event->getRequest()->headers->get('Accept');
        }

        // We should also check on entity = component
        if ($method != 'POST') {
            return;
        }

        if ($resource instanceof WebHook) {
            $resource->getRequest();
            $request = $this->commonGroundService->getResource($resource->getRequest());

            if ($request['status'] != 'complete') {
                $resource = $this->webHookService->handle($resource);
            }
        }
        $this->em->persist($resource);
        $this->em->flush();
    }
}
