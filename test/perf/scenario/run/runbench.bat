@echo php bench.php --recreate --rounds 100 --warmup 5 --busyloop 100000 IMPL > ../out/runbench.out
@echo measured on ThinkPad T540p >> ../out/runbench.out

@call runbench_pgsql.cmd
@call runbench_ivory.cmd
@call runbench_ivory-sync.cmd
@call runbench_ivory-nocache.cmd
@call runbench_dibi.cmd
@call runbench_dibi-lazy.cmd
@call runbench_doctrine.cmd
@call runbench_laravel.cmd
