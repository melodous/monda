#!/usr/bin/octave -qf

global opt;
source("monda.lib.m");


parseopts();
arg_list=getrestopts();

global hdata;

for i=1:length(arg_list)
 hdata=[];
 src=arg_list{i};
 if (index(src, ".m") > 0)
    loadsrc(src);
 else
    loaddata(src);
 end
 hostsinfo(hdata);

 for [host, hkey] = hdata
  if (ishost(host))
    hostinfo(host,hkey);
  end
 end
end

cminfo(hdata.cm);
mexit(0);