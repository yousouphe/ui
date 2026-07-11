-- Expands rider self-registration KYC to collect full biodata, guarantor details,
-- and vehicle/license documents, matching real-world rider vetting requirements.
ALTER TABLE rider_profiles
    ADD COLUMN IF NOT EXISTS kyc_age TINYINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS kyc_state_of_origin VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS kyc_lga_of_origin VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS kyc_hometown VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS kyc_national_id_number VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS kyc_address VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS kyc_proof_of_address_path VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS kyc_guarantor_name VARCHAR(150) NULL,
    ADD COLUMN IF NOT EXISTS kyc_guarantor_phone VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS kyc_guarantor_address VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS kyc_guarantor_relationship VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS kyc_vehicle_document_path VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS kyc_driving_license_path VARCHAR(255) NULL;
