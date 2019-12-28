-- Table: public.sites

-- DROP TABLE public.sites;

CREATE TABLE public.sites
(
  mcc smallint,
  mnc smallint,
  site integer,
  measurements numeric,
  rssi smallint,
  weight numeric,
  lat double precision,
  lon double precision,
  g geometry
)
WITH (
  OIDS=FALSE
);
ALTER TABLE public.sites
  OWNER TO kolesar;

-- Index: public.sites_geom

-- DROP INDEX public.sites_geom;

CREATE INDEX sites_geom
  ON public.sites
  USING gist
  (g);

-- Index: public.sites_site

-- DROP INDEX public.sites_site;

CREATE INDEX sites_site
  ON public.sites
  USING btree
  (site);
