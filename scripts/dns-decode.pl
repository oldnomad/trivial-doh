#!/usr/bin/perl -w
use strict;
use Net::DNS::Packet;

my $data = $ARGV[0] or die 'No data';
$data = pack('H*', $data);

my $packet = new Net::DNS::Packet(\$data);
$packet->print;
