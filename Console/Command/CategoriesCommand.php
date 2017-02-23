<?php
/**
 * Created by PhpStorm.
 * User: jidziak
 * Date: 22.02.17
 * Time: 12:34
 */
namespace Macopedia\CategoryImporter\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\ObjectManagerInterface;

class CategoriesCommand extends Command
{
    /**
     * Object manager factory
     *
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \Magento\Framework\File\Csv
     */
    private $fileCsv;

    /**
     * @var \Magento\Framework\App\Filesystem\DirectoryList
     */
    private $directoryList;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var array
     */
    private $requiredHeaders = [
        'id', 'name', 'parent_id'
    ];

    /**
     * This headers has pre defined values, you can change values optionally
     *
     * @var array
     */
    private $optionalHeaders = [
        'is_active', 'is_anchor', 'include_in_menu', 'custom_use_parent_settings'
    ];

    /**
     * This headers are additional data, can be extended from script parameter
     *
     * @var array
     */
    private $additionalHeaders = [
        'description', 'meta_title', 'meta_keywords', 'meta_description', 'url_key', 'url_path', 'position',
    ];

    /**
     * @var array
     */
    private $headersMap;

    /**
     * @var array
     */
    private $baseParentCategories;

    /**
     * @var array
     */
    private $childCategories;

    /**
     * @var array
     */
    private $errors;

    /**
     * @param ObjectManagerInterface $objectManager
     * @param \Magento\Framework\File\Csv $fileCsv
     * @param \Magento\Framework\App\Filesystem\DirectoryList $directoryList
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        \Magento\Framework\File\Csv $fileCsv,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        $this->objectManager = $objectManager;
        $this->fileCsv = $fileCsv;
        $this->directoryList = $directoryList;
        $this->storeManager = $storeManager;
        $this->storeManager->setCurrentStore('admin');
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('import:categories')
            ->setDescription('Run category importer script')
            ->setDefinition([
                new InputOption(
                    'path',
                    'p',
                    InputOption::VALUE_REQUIRED,
                    'Enter path to CSV file in Magento dir (eg. "var/import/categories.csv")'
                ),
                new InputOption(
                    'additional',
                    'a',
                    InputOption::VALUE_OPTIONAL,
                    'Enter custom category attribute codes separated by comma (eg. "my_custom_1,my_custom2,my_custom3")'
                )
            ]);

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return mixed
     * @throws LocalizedException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $path = $input->getOption('path');
            if (!$path) {
                throw new LocalizedException(__('Please specify path to file! (eg. "var/import/categories.csv")'));
            }
            $additionalHeadersDefinedByUser = explode(',', $input->getOption('additional'));
            $this->additionalHeaders = array_merge($this->additionalHeaders, $additionalHeadersDefinedByUser);

            $file = $this->directoryList->getRoot() . '/' . $path;

            if (!file_exists($file)) {
                throw new LocalizedException(__('File ' . $file . ' does not exist!'));
            }
            $this->fileCsv->setDelimiter(';');
            $data = $this->fileCsv->getData($file);
            $i = 0;
            foreach ($data as $row) {
                if ($i == 0) {
                    $this->mapHeaders($row);
                    foreach ($this->requiredHeaders as $requiredHeader) {
                        if (!array_key_exists($requiredHeader, $this->headersMap)) {
                            throw new LocalizedException(__('Required header "'
                                . $requiredHeader . '" is missing, please fix file'));
                        }
                    }
                    $i++;
                    continue;
                }
                if ($row[$this->headersMap['parent_id']] == 'NULL'
                    || $row[$this->headersMap['parent_id']] == 'null'
                    || $row[$this->headersMap['parent_id']] == ''
                    || !$row[$this->headersMap['parent_id']]) {
                    $this->baseParentCategories[] = $row;
                } else {
                    $this->childCategories[] = $row;
                }
            }

            foreach ($this->baseParentCategories as $category) {
                $this->addOrUpdateCategory($category, true);
            }

            foreach ($this->childCategories as $category) {
                $this->addOrUpdateCategory($category);
            }

            if (count($this->errors) > 0) {
                $output->writeln('There was ' . count($this->errors) . ' errors:');
                foreach ($this->errors as $error) {
                    $output->writeln($error);
                }
            } else {
                $output->writeln('Import completed successfully!');
            }
            return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $output->writeln($e->getTraceAsString());
            }
            // we must have an exit code higher than zero to indicate something was wrong
            return \Magento\Framework\Console\Cli::RETURN_FAILURE;
        }
    }

    /**
     * Returns value of additional attribute required by Magento
     *
     * @param $data
     * @param $header
     * @param bool|false $default
     * @return bool
     */
    protected function getOptionalAttributeValue($data, $header, $default = false)
    {
        if (array_key_exists($header, $this->headersMap) && isset($data[$this->headersMap[$header]])) {
            if ($data[$this->headersMap[$header]] == '1') {
                return true;
            } else {
                return false;
            }
        }
        return $default;
    }

    /**
     * Load category by old category ID
     *
     * @param $categoryFactory
     * @param $data
     * @param $id
     * @return mixed
     */
    protected function getCategoryFromCollectionByOldId($categoryFactory, $data, $id)
    {
        return $categoryCollection = $categoryFactory
            ->create()
            ->getCollection()
            ->addAttributeToFilter('old_category_id', $data[$this->headersMap[$id]])->setPageSize(1);
    }

    /**
     * Add or update category data
     *
     * @param $data
     * @param bool|false $isParent
     * @return bool
     */
    protected function addOrUpdateCategory($data, $isParent = false)
    {
        $categoryFactory = $this->objectManager->get('Magento\Catalog\Model\CategoryFactory');
        $categoryRepository = $this->objectManager->get('\Magento\Catalog\Api\CategoryRepositoryInterface');
        $categoryCollection = $this->getCategoryFromCollectionByOldId($categoryFactory, $data, 'id');
        if ($categoryCollection->getSize()) {
            $categoryId = $categoryCollection->getFirstItem()->getEntityId();
            $category = $categoryRepository->get($categoryId, 0);
        } else {
            $category = $categoryFactory->create();
        }
        $category->setName($data[$this->headersMap['name']]);
        $category->setIsActive($this->getOptionalAttributeValue($data, 'is_active', true));
        $category->setIncludeInMenu($this->getOptionalAttributeValue($data, 'include_in_menu', true));
        if ($isParent) {
            $category->setParentId(2);
        } else {
            $categoryCollection = $this->getCategoryFromCollectionByOldId($categoryFactory, $data, 'parent_id');
            if ($categoryCollection->getSize()) {
                $categoryId = $categoryCollection->getFirstItem()->getEntityId();
                $category->setParentId($categoryId);
            } else {
                $this->errors[] = 'ERROR (RECORD SKIPPED): Category "'
                    . $data[$this->headersMap['name']]
                    . '" does not have existing parent category!';
                return false;
            }
        }

        $additionalData = [
            'is_anchor' => $this->getOptionalAttributeValue($data, 'is_anchor', true),
            'custom_use_parent_settings' =>
                $this->getOptionalAttributeValue($data, 'custom_use_parent_settings', true),
            'old_category_id' => $data[$this->headersMap['id']],
        ];

        foreach ($this->additionalHeaders as $header) {
            if (array_key_exists($header, $this->headersMap)) {
                $additionalData[$header] = $data[$this->headersMap[$header]];
            }
        }

        $category->setCustomAttributes($additionalData);

        $categoryRepository->save($category);

        return true;
    }

    /**
     * Map headers from file to row keys
     *
     * @param array $row
     */
    protected function mapHeaders($row)
    {
        $headers = array_merge($this->requiredHeaders, $this->optionalHeaders, $this->additionalHeaders);
        foreach ($row as $key => $item) {
            foreach ($headers as $header) {
                if($item == $header) {
                    $this->headersMap[$header] = $key;
                }
            }
        }
    }
}