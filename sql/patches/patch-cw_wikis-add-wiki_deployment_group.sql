ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_deployment_group VARCHAR(64) NOT NULL DEFAULT 'default' AFTER wiki_category;
