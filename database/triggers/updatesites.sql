-- Function: public.updatesites()

-- DROP FUNCTION public.updatesites();

CREATE OR REPLACE FUNCTION public.updatesites()
  RETURNS trigger AS
$BODY$
BEGIN

IF (TG_OP = 'DELETE' OR
        (TG_OP = 'UPDATE' AND (
            old.mcc != new.mcc OR
            old.mnc != new.mnc OR
            old.site != new.site
        )
    )
) AND EXISTS (
        SELECT 1
        FROM sites
        WHERE mcc=old.mcc
        AND mnc=old.mnc
        AND site=old.site
)
THEN
    DELETE FROM sites
    WHERE mcc=old.mcc
    AND mnc=old.mnc
    AND site=old.site;

    INSERT INTO sites (measurements, rssi, weight, lat, lon, g)
    SELECT *, ST_SetSRID(ST_Point(lon, lat), 4326) AS g FROM (
        SELECT
        SUM(measurements) AS measurements,
        MAX(rssi) AS rssi,
        SUM(weight) AS weight,
        SUM(lat*weight)/SUM(weight) AS lat,
        SUM(lon*weight)/SUM(weight) AS lon
        FROM cells
        WHERE mcc=old.mcc
        AND mnc=old.mnc
        AND site=old.site
    ) AS averaged;
END IF;

IF TG_OP IN ('INSERT', 'UPDATE') THEN

    DELETE FROM sites
    WHERE mcc=new.mcc
    AND mnc=new.mnc
    AND site=new.site;

    INSERT INTO sites (mcc, mnc, site, measurements, rssi, weight, lat, lon, g)
    SELECT *, ST_SetSRID(ST_Point(lon, lat), 4326) AS g FROM (
        SELECT
        new.mcc,
        new.mnc,
        new.site,
        SUM(measurements) AS measurements,
        MAX(rssi) AS rssi,
        SUM(weight) AS weight,
        SUM(lat*weight)/SUM(weight) AS lat,
        SUM(lon*weight)/SUM(weight) AS lon
        FROM cells
        WHERE mcc=new.mcc
        AND mnc=new.mnc
        AND site=new.site
    ) AS averaged;

    RETURN new;

ELSIF TG_OP = 'DELETE' THEN
    RETURN old;

END IF;

END
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100;
ALTER FUNCTION public.updatesites()
  OWNER TO kolesar;
