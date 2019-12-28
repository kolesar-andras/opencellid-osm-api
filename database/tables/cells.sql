-- Table: public.cells

-- DROP TABLE public.cells;

CREATE TABLE public.cells
(
  mcc smallint,
  mnc smallint,
  net character varying,
  cellid integer,
  cell integer,
  site integer,
  rnc smallint,
  measurements bigint,
  rssi smallint,
  weight bigint,
  lat double precision,
  lon double precision,
  g geometry,
  lac integer,
  psc smallint
)
WITH (
  OIDS=FALSE
);
ALTER TABLE public.cells
  OWNER TO kolesar;

-- Index: public.cells_geom

-- DROP INDEX public.cells_geom;

CREATE INDEX cells_geom
  ON public.cells
  USING gist
  (g);

-- Index: public.cells_site

-- DROP INDEX public.cells_site;

CREATE INDEX cells_site
  ON public.cells
  USING btree
  (site);


-- Trigger: updatesites on public.cells

-- DROP TRIGGER updatesites ON public.cells;

CREATE TRIGGER updatesites
  AFTER INSERT OR UPDATE OR DELETE
  ON public.cells
  FOR EACH ROW
  EXECUTE PROCEDURE public.updatesites();
