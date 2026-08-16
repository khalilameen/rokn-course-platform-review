<div class="sidebar">
	<v-layout align-center justify-space-around>
		<v-avatar color="#092783" size="36" class="mr-auto">
			<span class="has-text-white headline">A</span>
		</v-avatar>

	</v-layout>
	<hr class="mb0 ml20 mr20"/>
	<div class="sidebar-slide">
		<v-expansion-panels :accordion="true" :focusable="true" class="collapse">
			<v-expansion-panel v-for="(item,i) in 10" :key="i" >
				<v-expansion-panel-header expand-icon="fas fa-angle-down icon" class="button is-transparent-primary">
					<span class="has-text-right"><i class="item-icon fas fa-home"></i> Item</span>
				</v-expansion-panel-header>
                <v-expansion-panel-content>
                    <v-list flat>
                        <v-list-item href="{{ route('admin.packages.index') }}">
                            <v-list-item-content>
                                <v-list-item-title class="has-text-right has-text-accent">
                                    <i class="fa fa-cubes ml-2"></i> الباقات
                                </v-list-item-title>
                            </v-list-item-content>
                        </v-list-item>

                        <v-list-item href="{{ route('admin.paths.index') }}">
                            <v-list-item-content>
                                <v-list-item-title class="has-text-right has-text-accent">
                                    <i class="fa fa-road ml-2"></i> المسارات
                                </v-list-item-title>
                            </v-list-item-content>
                        </v-list-item>
                    </v-list>
                </v-expansion-panel-content>

						<v-list-item-group {{-- v-model="item"  --}}color="primary">
							<v-list-item v-for="(item, i) in [
							{ text: 'Real-Time' },
							{ text: 'Audience' },
							{ text: 'Conversions'},
							]"	:key="i">
							<v-list-item-content>
								<v-list-item-title v-text="item.text" class="has-text-right has-text-accent"></v-list-item-title>
							</v-list-item-content>
						</v-list-item>
					</v-list-item-group>
				</v-list>
			</v-expansion-panel-content>
		</v-expansion-panel>
	</v-expansion-panels>
</div>
</div>
