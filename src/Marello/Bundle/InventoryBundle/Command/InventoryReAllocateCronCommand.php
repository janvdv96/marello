<?php

namespace Marello\Bundle\InventoryBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\WorkflowBundle\Entity\WorkflowItem;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Component\MessageQueue\Client\MessagePriority;
use Oro\Bundle\CronBundle\Command\CronCommandInterface;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use Oro\Bundle\EntityExtendBundle\Entity\Repository\EnumValueRepository;

use Marello\Bundle\WorkflowBundle\Async\Topics;
use Marello\Bundle\InventoryBundle\Entity\Allocation;
use Marello\Bundle\InventoryBundle\Provider\InventoryAllocationProvider;
use Marello\Bundle\InventoryBundle\Provider\AllocationStateStatusInterface;

class InventoryReAllocateCronCommand extends Command implements CronCommandInterface
{
    const COMMAND_NAME = 'oro:cron:marello:inventory:reallocate';
    const ALLOCATION_WORKFLOW_RESOLVED = 'resolved';
    const EXIT_CODE = 0;

    /** @var DoctrineHelper $doctrineHelper */
    protected $doctrineHelper;

    /** @var InventoryAllocationProvider $allocationProvider */
    protected $allocationProvider;

    /** @var MessageProducerInterface $messageProducer */
    protected $messageProducer;

    /**
     * InventoryReAllocateCronCommand constructor.
     * @param DoctrineHelper $doctrineHelper
     * @param InventoryAllocationProvider $allocationProvider
     */
    public function __construct(
        DoctrineHelper $doctrineHelper,
        InventoryAllocationProvider $allocationProvider,
        MessageProducerInterface $messageProducer
    ) {
        parent::__construct();
        
        $this->doctrineHelper = $doctrineHelper;
        $this->allocationProvider = $allocationProvider;
        $this->messageProducer = $messageProducer;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName(self::COMMAND_NAME)
            ->setDescription('Re allocate waiting for supply allocations');
    }

    /**
     * @inheritDoc
     */
    public function getDefaultDefinition()
    {
        return '*/10 * * * *';
    }

    /**
     * @inheritDoc
     */
    public function isActive()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $allocations = $this
            ->doctrineHelper
            ->getEntityRepositoryForClass(Allocation::class)
            ->findBy(['state' => 'waiting']);

        $em = $this->doctrineHelper->getEntityManagerForClass(Allocation::class);
        /** @var Allocation $allocation */
        foreach ($allocations as $allocation) {
            $whResults = $this->allocationProvider->getWarehouseResults($allocation->getOrder(), $allocation);
            foreach ($whResults as $orderWarehouseResults) {
                foreach ($orderWarehouseResults as $result) {
                    if (!in_array($result->getWarehouse()->getCode(), ['no_warehouse', 'could_not_allocate'])) {
                        $this->allocationProvider->allocateOrderToWarehouses($allocation->getOrder(), $allocation);
                        $allocation->setState(
                            $this->getEnumValue(
                                'marello_allocation_state',
                                AllocationStateStatusInterface::ALLOCATION_STATE_CLOSED
                            )
                        );
                        $allocation->setStatus(
                            $this->getEnumValue(
                                'marello_allocation_status',
                                AllocationStateStatusInterface::ALLOCATION_STATUS_CLOSED
                            )
                        );
                        $em->persist($allocation);
                        $this->updateAllocationWorkflow($allocation);
                    }
                }
            }
        }

        $em->flush();

        return self::EXIT_CODE;
    }

    /**
     * @param $enumClass
     * @param $value
     * @return object|null
     */
    protected function getEnumValue($enumClass, $value)
    {
        $className = ExtendHelper::buildEnumValueClassName($enumClass);
        /** @var EnumValueRepository $enumRepo */
        $enumRepo = $this->doctrineHelper
            ->getEntityRepositoryForClass($className);

        return $enumRepo->findOneBy(['id' => $value]);
    }

    /**
     * @param Allocation $allocation
     * @return void
     * @throws \Oro\Component\MessageQueue\Transport\Exception\Exception
     */
    protected function updateAllocationWorkflow(Allocation $allocation)
    {
        /** @var WorkflowItem|null $workflowItem */
        $workflowItem = $this->doctrineHelper
            ->getEntityRepositoryForClass(WorkflowItem::class)
            ->findOneBy(
                [
                    'entityId' => $allocation->getId(),
                    'entityClass' => Allocation::class
                ]
            );

        if ($workflowItem) {
            $this->messageProducer->send(
                Topics::WORKFLOW_TRANSIT_TOPIC,
                [
                    'workflow_item_entity_id' => $allocation->getId(),
                    'current_step_id' => $workflowItem->getCurrentStep()->getId(),
                    'entity_class' => Allocation::class,
                    'transition' => self::ALLOCATION_WORKFLOW_RESOLVED,
                    'jobId' => md5($allocation->getId()),
                    'priority' => MessagePriority::NORMAL
                ]
            );
        }
    }
}
