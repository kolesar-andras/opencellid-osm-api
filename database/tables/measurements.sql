-- Table: public.measurements

-- DROP TABLE public.measurements;

CREATE TABLE public.measurements
(
  mcc smallint,
  mnc smallint,
  lac integer,
  cellid integer,
  lon double precision,
  lat double precision,
  signal smallint,
  created bigint,
  measured bigint,
  rating double precision,
  speed double precision,
  direction double precision,
  radio character varying,
  ta smallint,
  rnc smallint,
  cid integer,
  psc smallint,
  tac integer,
  pci smallint,
  sid smallint,
  nid smallint,
  bid smallint,
  g geometry,
  id integer NOT NULL DEFAULT nextval('measurements_id_seq'::regclass),
  cell integer,
  site integer,
  rssi smallint,
  error character varying,
  net character varying,
  file integer,
  mnco smallint,
  neighbour boolean
)
WITH (
  OIDS=FALSE
);
ALTER TABLE public.measurements
  OWNER TO kolesar;

-- Index: public.measurements_g

-- DROP INDEX public.measurements_g;

CREATE INDEX measurements_g
  ON public.measurements
  USING gist
  (g);

-- Index: public.measurements_mcc_mnc_site

-- DROP INDEX public.measurements_mcc_mnc_site;

CREATE INDEX measurements_mcc_mnc_site
  ON public.measurements
  USING btree
  (mcc, mnc, site);


-- Trigger: setmeasurementcolumns on public.measurements

-- DROP TRIGGER setmeasurementcolumns ON public.measurements;

CREATE TRIGGER setmeasurementcolumns
  BEFORE INSERT
  ON public.measurements
  FOR EACH ROW
  EXECUTE PROCEDURE public.setmeasurementcolumns();

-- Trigger: updatecells on public.measurements

-- DROP TRIGGER updatecells ON public.measurements;

CREATE TRIGGER updatecells
  AFTER INSERT
  ON public.measurements
  FOR EACH ROW
  EXECUTE PROCEDURE public.updatecells();
