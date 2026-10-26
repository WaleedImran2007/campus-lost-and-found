-- OPTIONAL demo data (3 students + sample listings). Run AFTER schema.sql.
-- All demo students use password:  Student@123
USE campus_lost_found;
INSERT INTO users (student_id, full_name, email, password_hash, phone) VALUES
 ('bcsf24m001','Ayesha Khan','bcsf24m001@pucit.edu.pk','$2y$10$JxeYPuqZojsSf8B4RvUMfuDSNYjbgUyn1hlqZ4GDnyEYH3jmbcWbi','03001234567'),
 ('bcsf24m002','Bilal Ahmed','bcsf24m002@pucit.edu.pk','$2y$10$JxeYPuqZojsSf8B4RvUMfuDSNYjbgUyn1hlqZ4GDnyEYH3jmbcWbi',NULL),
 ('bcsf24m003','Sara Malik','bcsf24m003@pucit.edu.pk','$2y$10$JxeYPuqZojsSf8B4RvUMfuDSNYjbgUyn1hlqZ4GDnyEYH3jmbcWbi',NULL);

-- user_id 3,4,5 are the students above (1 = admin, 2 = custodian)
INSERT INTO items (reporter_id, report_type, title, category_id, location_id, description, incident_date, is_high_value) VALUES
 (3,'found','Black leather wallet',2,1,'Found on a table on the second floor of the library. Has some cards inside.',CURDATE() - INTERVAL 1 DAY,1),
 (4,'lost','Blue water bottle',8,2,'Lost my blue steel bottle near the cafeteria counter. Has a sticker on it.',CURDATE() - INTERVAL 2 DAY,0),
 (5,'found','Casio calculator',1,5,'Scientific calculator left in Lab 2 after the DSA class.',CURDATE() - INTERVAL 3 DAY,0),
 (3,'lost','House keys with red keychain',4,7,'Dropped somewhere in the parking area, three keys on a red ring.',CURDATE(),0);
INSERT INTO private_item_evidence (item_id, claim_id, evidence_type, submitted_by, private_description)
 VALUES (1,NULL,'item_detail',3,'A 500 rupee note is folded behind the cards; the student card inside has a PUCIT logo.');
