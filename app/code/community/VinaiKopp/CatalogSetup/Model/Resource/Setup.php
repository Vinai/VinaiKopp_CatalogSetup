<?php


class VinaiKopp_CatalogSetup_Model_Resource_Setup extends Mage_Catalog_Model_Resource_Setup
{
    protected $_groupsInSets = array();

    /**
     * Remove all but the super root and root categories
     *
     * WARNING: only use in setup context, that is after startSetup() was
     * called! This method leaves the execution context in setup mode regardless
     * if it was set beforehand!
     *
     * @param int $level
     */
    public function clearCategoryTable($level = 1)
    {
        // disable setup mode so foreign key constraints cascade delete
        $this->endSetup();
        $table = $this->getTable('catalog/category');
        $level = max(1, $level);
        $this->getConnection()->delete($table, array('level>?' => $level));
        $childCount = $this->getConnection()->fetchOne("SELECT COUNT(*) FROM `$table` WHERE level > 0");
        $this->getConnection()->update($table, array('children_count' => 0), array('level=?' => 1));
        $this->getConnection()->update($table, array('children_count' => $childCount), array('level=?' => 0));

        // Remove any inconsistent records from catalog_category_product since probably
        // FK constraints where disabled via startSetup()...
        //$rootCategories = $this->getConnection()->fetchCol("SELECT entity_id FROM `$table`");
        //$this->getConnection()->delete(
        //    $this->getTable('catalog/category_product', array('category_id NOT IN(?)' => $rootCategories))
        //);

        // re-disable foreign key constraints 
        $this->startSetup();
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
        if (!$sourceSet) {
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
            if (!$this->groupExistsInSet($entityType, $targetName, $groupName)) {
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
        $exists = (bool)$groupId;
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
            if (!isset($option[$storeId])) {
                Mage::throwException('No default option label specified for store ' . $storeId);
            }
            $label = $option[$storeId];
            if (!$source->getOptionId($label) && !isset($toCreate[$label])) {
                $toCreate[$label] = $option;
            }
        }
        if ($toCreate) {
            $maxOptionSortOrder = $this->getMaxAttributeOptionSortOrder($entityType, $attributeCode);
            $order = $value = array();
            $n = 0;
            foreach ($toCreate as $option) {
                $idx = 'a' . ($n++); // unique index that results in 0 when cast to int
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
        return (int)$this->getConnection()->fetchOne($select);
    }

    /**
     * Change an attribute option label from one value to another
     *
     * @param string $entityType Entity Type
     * @param string $attributeCode Attribute Code
     * @param string $from Old value
     * @param string $to New value
     * @param int $storeId Limit update to the specified store
     * @return int                   The number of affected rows.
     * @throws Mage_Core_Exception   Attribute not known
     * @throws Mage_Core_Model_Store_Exception
     */
    public function updateAttributeOptionLabel($entityType, $attributeCode, $from, $to, $storeId = null)
    {
        $attributeId = $this->getAttributeId($entityType, $attributeCode);
        if (!$attributeId) {
            Mage::throwException("EAV Attribute '$entityType' :: '$attributeCode' not found.");
        }
        $select = $this->getConnection()->select()
            ->from($this->getTable('eav/attribute_option'), 'option_id')
            ->where("attribute_id=?", $attributeId);
        $optionIds = $this->getConnection()->fetchCol($select);
        $numAffected = 0;
        if ($optionIds) {
            $table = $this->getTable('eav/attribute_option_value');
            $where = array(
                'option_id IN(?)' => $optionIds,
                'value = ?' => $from
            );
            if (!is_numeric($storeId)) {
                $storeId = Mage::app()->getStore($storeId)->getId();
                $where['store_id = ?'] = $storeId;
            }
            $numAffected = $this->getConnection()->update($table, array('value' => $to), $where);
        }
        return $numAffected;
    }

    /**
     * Add the product type to the attribute's apply_to property.
     *
     * @param string $entityType
     * @param string $attributeCode
     * @param string $productType
     */
    public function addProductTypeToAttributeApplyTo($entityType, $attributeCode, $productType)
    {
        $attribute = Mage::getSingleton('eav/config')->getAttribute($entityType, $attributeCode);
        $applyTo = $attribute->getApplyTo();
        if (is_array($applyTo) && !in_array($productType, $applyTo)) {
            $applyTo[] = $productType;
            $attribute->setApplyTo($applyTo)->save();
        }
    }

    /**
     * Prepare the labels defined for each store
     * return array (
     *  'attribute_id' => ID,
     *  'labels' => array('STORE_ID' => VALUE)
     * )
     *
     * @param array $labels
     * @param int $id
     * @param string $type
     * @return array $attribute
     */
    protected function _prepareLabels($labels, $id, $type = 'store')
    {
        $attribute = array();
        $attribute['attribute_id'] = $id;

        $storeSelect = $this->getConnection()->select()
            ->from($this->getTable('core_store'), array('store_id'))
            ->order('(store_id + 0)');

        $stores = $this->getConnection()->fetchAll($storeSelect);
        foreach ($stores as $store) {
            if ($type == 'store') {
                $attribute['labels'][$store['store_id']] = $labels[$store['store_id']];
            } else {
                $config = $this->getConnection()->select()
                    ->from($this->getTable('core_config_data'), array('value'))
                    ->where('scope = "stores" AND scope_id = ' . $store['store_id'] . ' AND path = "general/locale/code"');

                $locale = $this->getConnection()->fetchRow($config);
                if ($locale && array_key_exists($locale['value'], $labels)) {
                    $attribute['labels'][$store['store_id']] = $labels[$locale['value']];
                }
            }
        }
        return $attribute;
    }

    /**
     * Add the label to an attribute for each store if it exists
     * Delete the previous labels before to save the new one
     * $attribute must be prepared by the method prepareLabels()
     *
     * @param array $attribute
     * @return $this
     * @throws Mage_Core_Exception
     */
    protected function _addStoreLabels($attribute = array())
    {
        $storeLabels = $attribute['labels'];
        if (is_array($storeLabels)) {
            if (isset($attribute['attribute_id'])) {
                $condition = array('attribute_id = ?' => $attribute['attribute_id']);
                $this->getConnection()->delete($this->getTable('eav/attribute_label'), $condition);

                $data = array();
                foreach ($storeLabels as $storeId => $label) {
                    if (!$storeId || !strlen($label)) {
                        continue;
                    }
                    $data[] = array(
                        'attribute_id' => $attribute['attribute_id'],
                        'store_id' => $storeId,
                        'value' => $label
                    );
                }
                $this->getConnection()->insertMultiple(
                    $this->getTable('eav/attribute_label'), $data
                );
            } else {
                throw Mage::exception('Mage_Eav', (Mage::helper('eav')->__('Attribute ID is missing!')));
            }
        }
        return $this;
    }

    /**
     * Set the label text of an attribute for each store
     * $labels = array('STORE_ID' => VALUE)
     *
     * @param int|string $entityType
     * @param int|string $attribute
     * @param array $labels
     * @return $this
     */
    public function addAttributeStoreLabelsByStore($entityType, $attribute, $labels)
    {
        $attributeId = $this->getAttributeId($entityType, $attribute);
        $labels = $this->_prepareLabels($labels, $attributeId);
        $this->_addStoreLabels($labels);
        return $this;
    }

    /**
     * Set the label text of an attribute for each store via locale key
     * $labels = array('LOCALE' => VALUE)
     * e.g. LOCALE = de_DE or fr_FR
     *
     * @param int|string $entityType
     * @param int|string $attribute
     * @param array $labels
     * @return $this
     */
    public function addAttributeStoreLabelsByLocale($entityType, $attribute, $labels)
    {
        $attributeId = $this->getAttributeId($entityType, $attribute);
        $labels = $this->_prepareLabels($labels, $attributeId, 'locale');
        $this->_addStoreLabels($labels);
        return $this;
    }
} 
