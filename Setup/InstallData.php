<?php

namespace Macopedia\CategoryImporter\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;


class InstallData implements InstallDataInterface
{
    /**
     * @var \Magento\Eav\Setup\EavSetupFactory
     */
    private $eavSetupFactory;

    /**
     * @var \Magento\Eav\Setup\EavSetup $eavSetup
     */
    private $eavSetup;

    /**
     * @param \Magento\Eav\Setup\EavSetupFactory $eavSetupFactory
     */
    public function __construct(
        \Magento\Eav\Setup\EavSetupFactory $eavSetupFactory
    ) {
        $this->eavSetupFactory = $eavSetupFactory;
        $this->entityType = \Magento\Catalog\Model\Category::ENTITY;
    }

    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        $this->eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);
        $this->eavSetup->addAttribute(
            $this->entityType,
            'old_category_id',
            [
                'type' => 'varchar',
                'label' => 'Old category ID',
                'input' => 'text',
                'source' => '',
                'required' => false,
                'sort_order' => 100,
                'global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
                'group' => 'General Information',
            ]
        );

        $setup->endSetup();
    }
}
