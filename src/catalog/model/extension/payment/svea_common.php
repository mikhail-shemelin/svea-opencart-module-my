<?php

class ModelExtensionPaymentSveaCommon extends Model
{
    private function addTableColumnIfNotExist($table, $column, $type) {
        $query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . $table . "` LIKE '" . $column . "'");
        if ($query->num_rows == 0) {
            $this->db->query("ALTER TABLE `" . DB_PREFIX . $table . "` ADD `" . $column . "` " . $type);
        }
    }
    
    private function checkTables() {
        $this->addTableColumnIfNotExist('order', 'svea_order_uuid', 'VARCHAR(36) NULL');
    }

    public function updateOrderUuid($order_id) {
        $this->checkTables();

        // generate new uuid
        $new_uuid = $this->db->query("SELECT UUID() as uuid")->row['uuid'];
        $this->db->query("UPDATE `" . DB_PREFIX . "order` SET svea_order_uuid = '" . $new_uuid . "' WHERE order_id = '" . (int)$order_id . "'");
        return $new_uuid;
    }

    public function getOrderUiid($order_id) {
        $this->checkTables();
        $query = $this->db->query("SELECT svea_order_uuid FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$order_id . "'");
        return $query->row['svea_order_uuid'] ?? '';
    }

    public function getOrderStatusId($order_id) {
        $query = $this->db->query("SELECT order_status_id FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$order_id . "'");
        return $query->row['order_status_id'] ?? '';
    }


}