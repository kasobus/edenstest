<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CampaignBundle\Command;

use Mautic\CoreBundle\Command\ModeratedCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class TriggerCampaignCommand
 */
class TriggerCampaignCommand extends ModeratedCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mautic:campaigns:trigger')
            ->setAliases(
                array(
                    'mautic:campaign:trigger',
                    'mautic:trigger:campaigns',
                    'mautic:trigger:campaign'
                )
            )
            ->setDescription('Trigger timed events for published campaigns.')
            ->addOption(
                '--campaign-id',
                '-i',
                InputOption::VALUE_OPTIONAL,
                'Trigger events for a specific campaign.  Otherwise, all campaigns will be triggered.',
                null
            )
            ->addOption('--scheduled-only', null, InputOption::VALUE_NONE, 'Trigger only scheduled events')
            ->addOption('--negative-only', null, InputOption::VALUE_NONE, 'Trigger only negative events, i.e. with a "no" decision path.')
            ->addOption('--batch-limit', '-l', InputOption::VALUE_OPTIONAL, 'Set batch size of leads to process per round. Defaults to 100.', 100)
            ->addOption(
                '--max-events',
                '-m',
                InputOption::VALUE_OPTIONAL,
                'Set max number of events to process per campaign for this script execution. Defaults to all.',
                0
            )
            ->addOption('--force', '-f', InputOption::VALUE_NONE, 'Force execution even if another process is assumed running.');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();

        /** @var \Mautic\CoreBundle\Factory\MauticFactory $factory */
        $factory = $container->get('mautic.factory');

        /** @var \Mautic\CampaignBundle\Model\EventModel $model */
        $model = $factory->getModel('campaign.event');
        /** @var \Mautic\CampaignBundle\Model\CampaignModel $campaignModel */
        $campaignModel = $factory->getModel('campaign');
        $translator    = $factory->getTranslator();
        $em            = $factory->getEntityManager();

        // Set SQL logging to null or else will hit memory limits in dev for sure
        $em->getConnection()->getConfiguration()->setSQLLogger(null);

        $id           = $input->getOption('campaign-id');
        $scheduleOnly = $input->getOption('scheduled-only');
        $negativeOnly = $input->getOption('negative-only');
        $batch        = $input->getOption('batch-limit');
        $max          = $input->getOption('max-events');

        if (!$this->checkRunStatus($input, $output, ($id) ? $id : 'all')) {

            return 0;
        }

        if ($id) {
            /** @var \Mautic\CampaignBundle\Entity\Campaign $campaign */
            $campaign = $campaignModel->getEntity($id);

            if ($campaign !== null && $campaign->isPublished()) {
                $totalProcessed = 0;
                $output->writeln('<info>'.$translator->trans('mautic.campaign.trigger.triggering', array('%id%' => $id)).'</info>');

                if (!$negativeOnly && !$scheduleOnly) {
                    //trigger starting action events for newly added leads
                    $output->writeln('<comment>'.$translator->trans('mautic.campaign.trigger.starting').'</comment>');
                    $processed = $model->triggerStartingEvents($campaign, $totalProcessed, $batch, $max, $output);
                    $output->writeln(
                        '<comment>'.$translator->trans('mautic.campaign.trigger.events_executed', array('%events%' => $processed)).'</comment>'."\n"
                    );
                }

                if ((!$max || $totalProcessed < $max) && !$negativeOnly) {
                    //trigger scheduled events
                    $output->writeln('<comment>'.$translator->trans('mautic.campaign.trigger.scheduled').'</comment>');
                    $processed = $model->triggerScheduledEvents($campaign, $totalProcessed, $batch, $max, $output);
                    $output->writeln(
                        '<comment>'.$translator->trans('mautic.campaign.trigger.events_executed', array('%events%' => $processed)).'</comment>'."\n"
                    );
                }

                if ((!$max || $totalProcessed < $max) && !$scheduleOnly) {
                    //find and trigger "no" path events
                    $output->writeln('<comment>'.$translator->trans('mautic.campaign.trigger.negative').'</comment>');
                    $processed = $model->triggerNegativeEvents($campaign, $totalProcessed, $batch, $max, $output);
                    $output->writeln(
                        '<comment>'.$translator->trans('mautic.campaign.trigger.events_executed', array('%events%' => $processed)).'</comment>'."\n"
                    );
                }
            } else {
                $output->writeln('<error>'.$translator->trans('mautic.campaign.rebuild.not_found', array('%id%' => $id)).'</error>');
            }
        } else {
            $campaigns = $campaignModel->getEntities(
                array(
                    'iterator_mode' => true
                )
            );

            while (($c = $campaigns->next()) !== false) {
                $totalProcessed = 0;

                // Key is ID and not 0
                $c = reset($c);

                if ($c->isPublished()) {
                    $output->writeln('<info>'.$translator->trans('mautic.campaign.trigger.triggering', array('%id%' => $c->getId())).'</info>');
                    if (!$negativeOnly && !$scheduleOnly) {
                        //trigger starting action events for newly added leads
                        $output->writeln('<comment>'.$translator->trans('mautic.campaign.trigger.starting').'</comment>');
                        $processed = $model->triggerStartingEvents($c, $totalProcessed, $batch, $max, $output);
                        $output->writeln(
                            '<comment>'.$translator->trans('mautic.campaign.trigger.events_executed', array('%events%' => $processed)).'</comment>'."\n"
                        );
                    }

                    if ($max && $totalProcessed >= $max) {

                        continue;
                    }

                    if (!$negativeOnly) {
                        //trigger scheduled events
                        $output->writeln('<comment>'.$translator->trans('mautic.campaign.trigger.scheduled').'</comment>');
                        $processed = $model->triggerScheduledEvents($c, $totalProcessed, $batch, $max, $output);
                        $output->writeln(
                            '<comment>'.$translator->trans('mautic.campaign.trigger.events_executed', array('%events%' => $processed)).'</comment>'."\n"
                        );
                    }

                    if ($max && $totalProcessed >= $max) {

                        continue;
                    }

                    if (!$scheduleOnly) {
                        //find and trigger "no" path events
                        $output->writeln('<comment>'.$translator->trans('mautic.campaign.trigger.negative').'</comment>');
                        $processed = $model->triggerNegativeEvents($c, $totalProcessed, $batch, $max, $output);
                        $output->writeln(
                            '<comment>'.$translator->trans('mautic.campaign.trigger.events_executed', array('%events%' => $processed)).'</comment>'."\n"
                        );
                    }
                }

                $em->detach($c);
                unset($c);
            }

            unset($campaigns);
        }

        $this->completeRun();

        return 0;
    }
}
