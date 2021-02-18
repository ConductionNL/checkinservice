<?php

// src/Command/CreateUserCommand.php

namespace App\Command;

use App\Service\RequestService;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProcessCommand extends Command
{
    private $em;
    private $requestService;
    private $commonGroundService;

    public function __construct(EntityManagerInterface $em, RequestService $requestService, CommonGroundService $commonGroundService)
    {
        $this->em = $em;
        $this->requestService = $requestService;
        $this->commonGroundService = $commonGroundService;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
        ->setName('app:checkin:processrequests')
        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command will procces al the incomming onboarding requests')
            // the short description shown while running "php bin/console list"
        ->setDescription('Procces the onboarding requests')
        ->addOption('request', null, InputOption::VALUE_OPTIONAL, 'only procces a single request by its uuid');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Procces the onboarding requests');
        $io->text([
            'This command will',
            '- Get all the submitted onboarding requests from the vrc',
            '- Procces al those requests',
        ]);

        //$io->section('Removing old health checks');
        //$this->em->getRepository('App\Entity\HealthLog')->removeOld();

        /** @var string $version */
        $request = $input->getOption('request');

        if ($request) {
            $requests = $this->commonGroundService->getResourceList(['component'=>'vrc', 'type'=>'requests'], ['status'=>'submitted', 'request_type'=>'c328e6b4-77f6-4c58-8544-4128452acc80', 'id'=>$request])['hydra:member'];
        } else {
            $requests = $this->commonGroundService->getResourceList(['component'=>'vrc', 'type'=>'requests'], ['status'=>'submitted', 'request_type'=>'c328e6b4-77f6-4c58-8544-4128452acc80'])['hydra:member'];
        }

        if (!$requests || count($requests) < 1) {
            $io->error('Found no requests to procces');

            return;
        }

        $io->section('Starting proccing the requests');

        $io->text('Found '.count($requests).' requests to procces');

        $io->progressStart(count($requests));

        $results = [];

        foreach ($requests as $request) {
            $result = $this->requestService->processRequest([], $request);
            //$results[] = [$health->getInstallation()->getEnvironment()->getCluster()->getName(), $health->getDomain()->getName(), $health->getInstallation()->getEnvironment()->getName(), $health->getInstallation()->getName(), $health->getEndpoint(), $health->getStatus()];

            $io->progressAdvance();
        }
        $io->progressFinish();

        $io->success('All done');

        /*
        $io->section('results');
        $io->table(
            ['Cluster', 'Domain', 'Enviroment', 'Installation', 'Endpoint', 'Status'],
            $results
        );
        */
    }
}
