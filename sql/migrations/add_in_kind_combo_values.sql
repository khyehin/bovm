-- Allow IN records that are invoice documents but financially treated as repayment/bonus.
ALTER TABLE `customer_txn`
  MODIFY `in_kind` ENUM('INVOICE','RETURN','BONUS','ALLOCATE','INVOICE+RETURN','INVOICE+BONUS') NOT NULL DEFAULT 'INVOICE';
