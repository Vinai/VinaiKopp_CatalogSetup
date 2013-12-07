<?php


class VinaiKopp_CatalogSetup_Model_Resource_Setup extends Mage_Catalog_Model_Resource_Setup
{
    protected $_groupsInSets = array();
    
    /**
     * Remove all but the super root and root categories
     */
    public function clearCategoryTable()
    {
        $table = $this->getTable('catalog/category');
        $this->getConnection()->delete($table, array('level>?' => 1));
        $childCount = $this->getConnection()->fetchOne("SELECT COUNT(*) FROM `$table` WHERE level > 0");
        $this->getConnection()->update($table, array('children_count' => 0), array('level=?' => 1));
        $this->getConnection()->update($table, array('children_count' => $childCount), array('level=?' => 0));
    }

    /**
     * Create a new attribute set as a copy from an existing one
     *
     * @param string|int $entityType
     * @param string $sourceName
     * @param string $targetName
     */
    public function copyAttributeSet($entityType, $sourceName, $targetName)
    {
        $sourceSet = $this->getAttributeSet($entityType, $sourceName);
        if (! $sourceSet) {
            Mage::throwException("Unable to load source attribute set '$sourceName");
        }
        $select = $this->getConnection()->select()
            ->from($this->getTable('eav/entity_attribute'), '*')
            ->where('attribute_set_id = ?', $sourceSet['attribute_set_id']);
        $sourceConfig = $this->getConnection()->fetchAll($select);

        $this->addAttributeSet($entityType, $targetName);

        foreach ($sourceConfig as $row) {
            $group = $this->getAttributeGroup(
                $entityType, $row['attribute_set_id'], $row['attribute_group_id']
            );
            $groupName = $group['attribute_group_name'];
            if (! $this->groupExistsInSet($entityType, $targetName, $groupName)) {
                $this->addAttributeGroup(
                    $entityType, $targetName, $groupName, $group['sort_order']
                );
            }
            $this->addAttributeToGroup(
                $entityType, $targetName, $groupName, $row['attribute_id'], $row['sort_order']
            );
        }
    }

    /**
     * Check if the specified attribute set contains a given group
     * 
     * @param string $entityType
     * @param string $setName
     * @param string $groupName
     * @return bool
     */
    public function groupExistsInSet($entityType, $setName, $groupName)
    {
        $key = "$entityType-$setName-$groupName";
        if (isset($this->_groupsInSets[$key])) {
            return $this->_groupsInSets[$key];
        }
        $groupId = $this->getAttributeGroup($entityType, $setName, $groupName, 'attribute_group_id');
        $exists = (bool) $groupId;
        if ($exists) {
            // Only cache positive results
            $this->_groupsInSets[$key] = $exists;
        }
        return $exists;
    }

    /**
     * Add attribute options if they don't already exist
     * 
     * Format of $newOptionLabels:
     * array(
     *    array(0 => 'Label', 1 => 'Label),
     *    array(0 => '...',   1 => '...').
     *    ...
     * )
     *
     * The array keys are the store ids, 0 has to be present as it is the default.
     *
     * @param string|int $entityType
     * @param string|int $attributeCode
     * @param array $newOptionLabels
     */
    public function addAttributeOptionsIfNotPresent($entityType, $attributeCode, array $newOptionLabels)
    {
        $storeCode = 'admin';
        $store = Mage::app()->getStore($storeCode);
        $storeId = $store->getId();
        $attribute = Mage::getSingleton('eav/config')->getAttribute($entityType, $attributeCode);
        $source = $attribute->setStoreId($store->getId())->getSource();
        $toCreate = array();
        foreach ($newOptionLabels as $option) {
            if (! isset($option[$storeId])) {
                Mage::throwException('No default option label specified for store ' . $storeId);
            }
            $label = $option[$storeId];
            if (! $source->getOptionId($label) && ! isset($toCreate[$label])) {
                $toCreate[$label] = $option;
            }
        }
        if ($toCreate) {
            $maxOptionSortOrder = $this->getMaxAttributeOptionSortOrder($entityType, $attributeCode);
            $order = $value = array();
            foreach ($toCreate as $option) {
                $idx = 'a' . count($order); // unique index that results in 0 when cast to int
                $order[$idx] = ++$maxOptionSortOrder;
                $value[$idx] = $option;
            }
            $this->addAttributeOption(array(
                'attribute_id' => $attribute->getId(),
                'order' => $order,
                'value' => $value
            ));
        }
    }

    /**
     * Return the maximum option sort_order for a given attribute. 
     * 
     * @param string $entityType
     * @param string $attributeCode
     * @return int
     */
    public function getMaxAttributeOptionSortOrder($entityType, $attributeCode)
    {
        $attributeId = $this->getAttributeId($entityType, $attributeCode);
        $select = $this->getConnection()->select()
            ->from($this->getTable('eav/attribute_option'), new Zend_Db_Expr('MAX(sort_order)'))
            ->where("attribute_id=?", $attributeId);

        // Return 0 if no match
        return (int) $this->getConnection()->fetchOne($select);
    }
} 