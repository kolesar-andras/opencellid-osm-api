-- Function: public.updatecells()

-- DROP FUNCTION public.updatecells();

CREATE OR REPLACE FUNCTION public.updatecells()
  RETURNS trigger AS
$BODY$
<<this>>
DECLARE
weight integer;

BEGIN
IF new.net IS NULL THEN return new; END IF;
weight := CASE WHEN new.rssi IS NULL THEN 1 WHEN new.rssi<=-89 THEN 1 ELSE new.rssi+89 END;

IF EXISTS (
    SELECT 1 FROM lac
    WHERE mcc=new.mcc
    AND mnc=new.mnc
    AND net=new.net
    AND cellid=new.cellid
    AND site=new.site
    AND lac=new.lac
)
THEN
    UPDATE lac SET
    count = count+1
    WHERE mcc=new.mcc
    AND mnc=new.mnc
    AND net=new.net
    AND cellid=new.cellid
    AND site=new.site
    AND lac=new.lac;
ELSE
    INSERT INTO lac (mcc, mnc, net, site, cellid, lac, count)
    VALUES (new.mcc, new.mnc, new.net, new.site, new.cellid, new.lac, 1);
END IF;

IF (new.psc IS NOT NULL) THEN
IF EXISTS (
    SELECT 1 FROM psc
    WHERE mcc=new.mcc
    AND mnc=new.mnc
    AND net=new.net
    AND cellid=new.cellid
    AND site=new.site
    AND psc=new.psc
)
THEN
    UPDATE psc SET
    count = count+1
    WHERE mcc=new.mcc
    AND mnc=new.mnc
    AND net=new.net
    AND cellid=new.cellid
    AND site=new.site
    AND psc=new.psc;
ELSE
    INSERT INTO psc (mcc, mnc, net, site, cellid, psc, count)
    VALUES (new.mcc, new.mnc, new.net, new.site, new.cellid, new.psc, 1);
END IF;
END IF;

IF EXISTS (
    SELECT 1
    FROM cells
    WHERE mcc=new.mcc
    AND mnc=new.mnc
    AND net=new.net
    AND cellid=new.cellid
    AND cell=new.cell
    AND site=new.site
)
THEN
    UPDATE cells SET
    measurements = cells.measurements + 1,
    weight = cells.weight + this.weight,
    rssi = GREATEST(cells.rssi, new.rssi),
    lon = (cells.lon * cells.weight + new.lon * this.weight) / (cells.weight + this.weight),
    lat = (cells.lat * cells.weight + new.lat * this.weight) / (cells.weight + this.weight),
    g = ST_SetSRID(ST_Point(
        (cells.lon * cells.weight + new.lon * this.weight) / (cells.weight + this.weight),
        (cells.lat * cells.weight + new.lat * this.weight) / (cells.weight + this.weight)
    ), 4326),
    lac = (
        SELECT lac FROM lac
        WHERE mcc=new.mcc
        AND mnc=new.mnc
        AND net=new.net
        AND cellid=new.cellid
        AND site=new.site
        ORDER BY count DESC
        LIMIT 1
    ),
    psc = (
        SELECT psc FROM psc
        WHERE mcc=new.mcc
        AND mnc=new.mnc
        AND net=new.net
        AND cellid=new.cellid
        AND site=new.site
        ORDER BY count DESC
        LIMIT 1
    )
    WHERE mcc=new.mcc
    AND mnc=new.mnc
    AND net=new.net
    AND cellid=new.cellid
    AND cell=new.cell
    AND site=new.site;

ELSE
    INSERT INTO cells (
        mcc, mnc, net, cellid, cell, site, rnc,
        measurements, weight, rssi, lat, lon, lac, psc,
        g
    )
    VALUES (
        new.mcc, new.mnc, new.net, new.cellid, new.cell, new.site, new.rnc,
        1, weight, new.rssi, new.lat, new.lon, new.lac, new.psc,
        ST_SetSRID(ST_Point(new.lon, new.lat), 4326)
    );

END IF;
RETURN new;
END
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100;
ALTER FUNCTION public.updatecells()
  OWNER TO kolesar;
