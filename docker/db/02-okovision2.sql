CREATE DATABASE IF NOT EXISTS okovision2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS okovision2_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON okovision2.* TO 'okovision'@'%';
GRANT ALL PRIVILEGES ON okovision2_test.* TO 'okovision'@'%';
