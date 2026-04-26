-- ============================================================
-- Mock rezervacije – restavracija 4, maj 2026
-- Mize: 1 (terasa 1, cap 4), 2 (terasa 2, cap 6),
--        3 (pritličje-3, cap 2), 4 (pritličje-4, cap 6),
--        6 (Miza 88, cap 6), 7 (Miza 77, cap 4), 8 (Miza 99, cap 2)
-- ============================================================

DROP PROCEDURE IF EXISTS gen_mock_rez;

DELIMITER //
CREATE PROCEDURE gen_mock_rez()
BEGIN
    DECLARE d        DATE         DEFAULT '2026-04-01';
    DECLARE admin_id INT          DEFAULT 0;
    DECLARE i        INT;
    DECLARE nm       INT;
    DECLARE tbl_id   INT;
    DECLARE gc       INT;
    DECLARE tm       VARCHAR(8);
    DECLARE stat     VARCHAR(12);
    DECLARE new_res_id INT;

    SELECT user_id INTO admin_id FROM restaurant_admins WHERE restaurant_id = 4 LIMIT 1;

    WHILE d <= '2026-04-30' DO
        SET i = 1;
        WHILE i <= 12 DO
            SET nm = MOD(DAYOFYEAR(d) * 3 + i * 7, 20) + 1;

            SET tm = ELT(i,
                '12:00','12:30','13:00','13:30','14:00','14:30',
                '18:00','18:30','19:00','19:30','20:00','20:30'
            );

            -- Miza: ciklično po 7 mizah (id-ji: 1,2,3,4,6,7,8)
            SET tbl_id = ELT(MOD(i + DAYOFYEAR(d), 7) + 1, 1, 2, 3, 4, 6, 7, 8);

            -- Gostje glede na kapaciteto mize
            SET gc = CASE tbl_id
                WHEN 3 THEN ELT(MOD(i, 2) + 1, 1, 2)
                WHEN 8 THEN ELT(MOD(i, 2) + 1, 1, 2)
                WHEN 1 THEN ELT(MOD(i, 3) + 1, 2, 3, 4)
                WHEN 7 THEN ELT(MOD(i, 3) + 1, 2, 3, 4)
                ELSE        ELT(MOD(i, 4) + 1, 2, 3, 4, 6)
            END;

            SET stat = IF(MOD(DAYOFYEAR(d) + i, 5) = 0, 'pending', 'confirmed');

            INSERT INTO reservations
                (restaurant_id, reservation_date, reservation_time, guest_name, guest_count, phone, notes, status, source, created_by)
            VALUES (
                4,
                d,
                tm,
                ELT(nm,
                    'Ana Novak','Maja Kovač','Peter Horvat','Luka Krajnc','Sara Zupan',
                    'Matej Oblak','Nina Kern','Rok Vidmar','Eva Kranjc','Tina Šuštar',
                    'Bojan Ilić','Mojca Fink','Andrej Kos','Katja Mrak','Simon Berger',
                    'Urška Beg','Gregor Petan','Lea Franc','Žiga Jenko','Polona Hren'
                ),
                gc,
                ELT(MOD(nm, 10) + 1,
                    '+386 41 100 200','+386 51 201 301','+386 31 302 402',
                    '+386 40 403 503','+386 70 504 604','+386 41 605 705',
                    '+386 51 706 806','+386 31 807 907','+386 70 908 008',
                    '+386 41 009 109'
                ),
                NULL,
                stat,
                'admin',
                admin_id
            );

            SET new_res_id = LAST_INSERT_ID();

            INSERT INTO reservation_table_assignments
                (reservation_id, table_id, merge_group_id, assigned_by)
            VALUES
                (new_res_id, tbl_id, NULL, admin_id);

            SET i = i + 1;
        END WHILE;

        SET d = DATE_ADD(d, INTERVAL 1 DAY);
    END WHILE;
END //
DELIMITER ;

CALL gen_mock_rez();
DROP PROCEDURE IF EXISTS gen_mock_rez;

-- Preveri:
-- SELECT r.reservation_date, COUNT(*) AS rez, COUNT(a.id) AS z_mizo
-- FROM reservations r
-- LEFT JOIN reservation_table_assignments a ON a.reservation_id = r.id
-- WHERE r.restaurant_id = 4 AND r.reservation_date BETWEEN '2026-05-01' AND '2026-05-31'
-- GROUP BY r.reservation_date ORDER BY r.reservation_date;
