<?php

namespace App\Subscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use App\Entity\WebHook;
use App\Service\TrouwService;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\SerializerInterface;

class WebHookSubscriber implements EventSubscriberInterface
{
    private $params;
    private $trouwService;
    private $serializer;
    private $commonGroundService;

    public function __construct(ParameterBagInterface $params, TrouwService $trouwService, CommongroundService $commonGroundService, SerializerInterface $serializer)
    {
        $this->params = $params;
        $this->trouwService = $trouwService;
        $this->commonGroundService = $commonGroundService;
        $this->serializer = $serializer;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::VIEW => ['webHook', EventPriorities::PRE_VALIDATE],
        ];
    }

    public function webHook(ViewEvent $event)
    {
        $webHook = $event->getControllerResult();

        if($webHook instanceof WebHook){
            $this->trouwService->webHook($webHook);
        }
    }
}
