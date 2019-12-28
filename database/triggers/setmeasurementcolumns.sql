-- Function: public.setmeasurementcolumns()

-- DROP FUNCTION public.setmeasurementcolumns();

CREATE OR REPLACE FUNCTION public.setmeasurementcolumns()
  RETURNS trigger AS
$BODY$
BEGIN

IF (new.g IS NULL) THEN
    new.g := ST_SetSRID(ST_MakePoint(new.lon, new.lat), 4326);
END IF;

IF (new.cell IS NULL) THEN
    new.cell := CASE
        WHEN new.radio='LTE' THEN new.cellid & 255
        ELSE new.cellid & 65535
    END;
END IF;

IF (new.site IS NULL) THEN
    new.site := CASE
        WHEN new.mnc=30 AND new.radio IN ('GSM', 'UMTS') THEN -1
        ELSE 1
    END *
    CASE
        WHEN new.radio='LTE' THEN new.cellid>>8
        WHEN new.mnc=30 THEN new.cell
        ELSE (new.cellid & 65535)/10
    END;
END IF;

IF (new.rssi IS NULL) THEN
    new.rssi=CASE
        WHEN new.signal>60 THEN -new.signal
        WHEN new.signal<0 THEN new.signal
        ELSE 2*new.signal-113
    END;
END IF;

IF (new.mnco IS NULL) THEN
    new.mnco := new.mnc;
    new.mnc := CASE
        WHEN new.radio = 'LTE' AND new.mnc=01 AND new.lac BETWEEN 30000 AND 39999 THEN 30
        WHEN new.radio = 'LTE' AND new.mnc=30 AND (
                (new.lac BETWEEN 20000 AND 29999) OR
                (new.lac BETWEEN 5000 AND 5999)
            ) THEN 01
        ELSE new.mnc
    END;
END IF;

if (new.net) IS NULL THEN
    new.net := CASE
        WHEN (
                (new.mcc=216 AND new.mnc=01 AND new.lac BETWEEN 3000 AND 3999) OR
                (new.mcc=216 AND new.mnc=30 AND new.lac BETWEEN 1 AND 999) OR
                (new.mcc=216 AND new.mnc=70 AND new.lac BETWEEN 100 AND 199)
        )
        AND new.cellid<65536
        AND COALESCE(new.radio, 'GSM') = 'GSM'
        THEN 'gsm'

        WHEN (
                (new.mcc=216 AND new.mnc=01 AND new.lac BETWEEN 4000 AND 4999) OR
                (new.mcc=216 AND new.mnc=30 AND new.lac BETWEEN 1 AND 9999) OR
                (new.mcc=216 AND new.mnc=70 AND new.lac BETWEEN 200 AND 299)
        )
        AND new.cellid>65535
        AND new.radio = 'UMTS'
        THEN 'umts'

        WHEN (
                (new.mcc=216 AND new.mnc=01 AND new.lac BETWEEN 5000 AND 5999) OR
                (new.mcc=216 AND new.mnc=01 AND new.lac BETWEEN 20000 AND 39999) OR
                (new.mcc=216 AND new.mnc=30) OR
                (new.mcc=216 AND new.mnc=70 AND new.lac BETWEEN 3000 AND 3999)
        )
        AND new.cellid>255
        AND new.radio = 'LTE'
        THEN 'lte'
    END;
END IF;

RETURN new;
END
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100;
ALTER FUNCTION public.setmeasurementcolumns()
  OWNER TO kolesar;
