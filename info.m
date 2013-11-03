#!/usr/bin/octave -qf

source "monda.lib.m";

arg_list=argv();
src=arg_list{1};

global hdata;

loaddata(src);

hostsinfo(hdata);

for [host, hkey] = hdata
  if (isstruct(host))
    hostinfo(host);
  end
end