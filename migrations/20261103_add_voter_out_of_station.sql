ALTER TABLE voters
  ADD COLUMN out_of_station TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN out_of_station_phone VARCHAR(20) NULL;
