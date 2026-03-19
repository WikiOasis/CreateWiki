CREATE TABLE /*_*/cw_deployment_groups (
  cdg_name VARCHAR(64) NOT NULL PRIMARY KEY,
  cdg_deployment VARCHAR(128) NOT NULL,
  cdg_created BINARY(14) NOT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/cdg_deployment ON /*_*/cw_deployment_groups (cdg_deployment);
